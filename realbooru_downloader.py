#!/usr/bin/env python3
"""
Realbooru Downloader
Pobiera posty z realbooru.com (obrazki oraz wideo MP4/WebM), zapisuje pliki i miniaturki,
oraz dodaje wpisy do bazy danych SQLite (w tym automatyczny dodatek tagu 'realbooru').
Obsługuje wielowątkowość dla szybkiego pobierania bez limitów stron.
"""

import os
import sys
import re
import hashlib
import urllib.request
import urllib.parse
import json
import argparse
import time
import subprocess
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

# -----------------------------------------------------------------------------
# Database abstraction (Native sqlite3 with fallback to PHP/PDO)
# -----------------------------------------------------------------------------

class Database:
    def __init__(self, db_path):
        self.db_path = db_path
        self.use_native = False
        self.lock = threading.Lock()
        self.existing_post_ids = set()
        self.existing_md5s = set()
        try:
            import sqlite3
            self.conn = sqlite3.connect(db_path, check_same_thread=False, timeout=30.0)
            self.conn.execute("PRAGMA journal_mode = WAL")
            self.conn.execute("PRAGMA foreign_keys = ON")
            self.use_native = True
        except Exception:
            self.use_native = False
        self._init_db_schema()
        self._load_existing_cache()

    def _exec_php_sql(self, code, args=()):
        php_script = f"""
        try {{
            $db = new PDO('sqlite:' . $argv[1]);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            {code}
        }} catch (Throwable $e) {{
            fwrite(STDERR, $e->getMessage());
            exit(1);
        }}
        """
        res = subprocess.run(['php', '-r', php_script, self.db_path, *[str(a) for a in args]],
                             capture_output=True, text=True)
        if res.returncode != 0:
            raise RuntimeError(f"Database error: {res.stderr.strip()}")
        return res.stdout.strip()

    def _init_db_schema(self):
        sql = """
        CREATE TABLE IF NOT EXISTS posts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER,
            filename   TEXT NOT NULL UNIQUE,
            ext        TEXT NOT NULL,
            mime       TEXT NOT NULL,
            filesize   INTEGER NOT NULL,
            width      INTEGER,
            height     INTEGER,
            md5        TEXT NOT NULL UNIQUE,
            rating     TEXT NOT NULL DEFAULT 'q',
            source     TEXT,
            title      TEXT,
            score      INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT (unixepoch())
        );

        CREATE TABLE IF NOT EXISTS tags (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            name  TEXT NOT NULL UNIQUE COLLATE NOCASE,
            count INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS post_tags (
            post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            tag_id  INTEGER NOT NULL REFERENCES tags(id)  ON DELETE CASCADE,
            PRIMARY KEY (post_id, tag_id)
        );

        CREATE INDEX IF NOT EXISTS idx_posts_title ON posts(title);
        CREATE INDEX IF NOT EXISTS idx_posts_md5 ON posts(md5);
        """
        if self.use_native:
            with self.lock:
                self.conn.executescript(sql)
                self.conn.commit()
        else:
            with self.lock:
                code = "$db->exec($argv[2]);"
                self._exec_php_sql(code, [sql])

    def _load_existing_cache(self):
        self.existing_post_ids.clear()
        self.existing_md5s.clear()

        def _extract_realbooru_id(title, source):
            """Wyciąga Realbooru post ID z tytułu lub URL źródła."""
            if title and title.startswith("Realbooru #"):
                return title.split("#")[-1].strip()
            if source and "realbooru.com" in source:
                m = re.search(r'[?&]id=(\d+)', source)
                if m:
                    return m.group(1)
            return None

        with self.lock:
            if self.use_native:
                cur = self.conn.cursor()
                cur.execute("SELECT title, source, md5 FROM posts")
                rows = cur.fetchall()
                for title, source, md5 in rows:
                    pid = _extract_realbooru_id(title, source)
                    if pid:
                        self.existing_post_ids.add(str(pid))
                    if md5:
                        self.existing_md5s.add(md5)
            else:
                code = """
                $st = $db->query('SELECT title, source, md5 FROM posts');
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($rows);
                """
                try:
                    out = self._exec_php_sql(code)
                    if out:
                        rows = json.loads(out)
                        for row in rows:
                            title = row.get('title')
                            source = row.get('source')
                            md5 = row.get('md5')
                            pid = _extract_realbooru_id(title, source)
                            if pid:
                                self.existing_post_ids.add(str(pid))
                            if md5:
                                self.existing_md5s.add(md5)
                except Exception as e:
                    print(f"[Ostrzeżenie] Nie udało się załadować pamięci podręcznej z bazy: {e}")

        print(f"[Cache] Załadowano {len(self.existing_post_ids)} ID postów Realbooru i {len(self.existing_md5s)} sum MD5 z bazy.")


    def has_post_md5(self, md5):
        if md5 in self.existing_md5s:
            return True
        with self.lock:
            if self.use_native:
                cur = self.conn.cursor()
                cur.execute("SELECT id FROM posts WHERE md5 = ?", (md5,))
                found = cur.fetchone() is not None
            else:
                code = """
                $st = $db->prepare('SELECT id FROM posts WHERE md5 = ?');
                $st->execute([$argv[2]]);
                echo $st->fetchColumn() ? '1' : '0';
                """
                out = self._exec_php_sql(code, [md5])
                found = (out == '1')
            if found:
                self.existing_md5s.add(md5)
            return found

    def has_post_id(self, post_id):
        if str(post_id) in self.existing_post_ids:
            return True
        title = f"Realbooru #{post_id}"
        with self.lock:
            if self.use_native:
                cur = self.conn.cursor()
                cur.execute("SELECT id FROM posts WHERE title = ?", (title,))
                found = cur.fetchone() is not None
            else:
                code = """
                $st = $db->prepare('SELECT id FROM posts WHERE title = ?');
                $st->execute([$argv[2]]);
                echo $st->fetchColumn() ? '1' : '0';
                """
                out = self._exec_php_sql(code, [title])
                found = (out == '1')
            if found:
                self.existing_post_ids.add(str(post_id))
            return found

    def insert_post(self, post_data, tags):
        """
        post_data: dict with filename, ext, mime, filesize, width, height, md5, rating, source, title
        tags: list of tag names
        Returns post_id
        """
        if 'quality' not in post_data:
            post_data['quality'] = determine_quality(post_data.get('width'), post_data.get('height'))

        with self.lock:
            if self.use_native:
                cur = self.conn.cursor()
                cur.execute("""
                    INSERT INTO posts (user_id, filename, ext, mime, filesize, width, height, md5, rating, source, title, quality)
                    VALUES (1, :filename, :ext, :mime, :filesize, :width, :height, :md5, :rating, :source, :title, :quality)
                """, post_data)
                post_id = cur.lastrowid

                cleaned_tags = sorted(list(set(
                    re.sub(r'\s+', '_', t.strip().lower()) for t in tags if t.strip()
                )))

                for tag_name in cleaned_tags:
                    cur.execute("INSERT OR IGNORE INTO tags (name) VALUES (?)", (tag_name,))
                    cur.execute("SELECT id FROM tags WHERE name = ?", (tag_name,))
                    tag_id = cur.fetchone()[0]
                    cur.execute("INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)", (post_id, tag_id))
                    cur.execute("UPDATE tags SET count = count + 1 WHERE id = ?", (tag_id,))

                self.conn.commit()

                if 'title' in post_data and post_data['title'].startswith("Realbooru #"):
                    pid = post_data['title'].split("#")[-1]
                    self.existing_post_ids.add(str(pid))
                if 'md5' in post_data:
                    self.existing_md5s.add(post_data['md5'])

                return post_id
            else:
                code = """
                $p = json_decode($argv[2], true);
                $tags = json_decode($argv[3], true);

                $st = $db->prepare('
                    INSERT INTO posts (user_id, filename, ext, mime, filesize, width, height, md5, rating, source, title, quality)
                    VALUES (1, :filename, :ext, :mime, :filesize, :width, :height, :md5, :rating, :source, :title, :quality)
                ');
                $st->execute([
                    ':filename' => $p['filename'],
                    ':ext'      => $p['ext'],
                    ':mime'     => $p['mime'],
                    ':filesize' => $p['filesize'],
                    ':width'    => $p['width'],
                    ':height'   => $p['height'],
                    ':md5'      => $p['md5'],
                    ':rating'   => $p['rating'],
                    ':source'   => $p['source'],
                    ':title'    => $p['title'],
                    ':quality'  => $p['quality'],
                ]);
                $postId = (int)$db->lastInsertId();

                foreach ($tags as $t) {
                    $name = strtolower(preg_replace('/\\s+/', '_', trim($t)));
                    if ($name === '') continue;
                    $st = $db->prepare('INSERT OR IGNORE INTO tags (name) VALUES (?)');
                    $st->execute([$name]);
                    $st = $db->prepare('SELECT id FROM tags WHERE name = ?');
                    $st->execute([$name]);
                    $tagId = (int)$st->fetchColumn();
                    $st = $db->prepare('INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)');
                    $st->execute([$postId, $tagId]);
                    $st = $db->prepare('UPDATE tags SET count = count + 1 WHERE id = ?');
                    $st->execute([$tagId]);
                }
                echo $postId;
                """
                out = self._exec_php_sql(code, [json.dumps(post_data), json.dumps(tags)])
                post_id = int(out) if out.isdigit() else 0
                if post_id > 0:
                    if 'title' in post_data and post_data['title'].startswith("Realbooru #"):
                        pid = post_data['title'].split("#")[-1]
                        self.existing_post_ids.add(str(pid))
                    if 'md5' in post_data:
                        self.existing_md5s.add(post_data['md5'])
                return post_id

# -----------------------------------------------------------------------------
# Image & Video Utilities (Dimensions and Thumbnails)
# -----------------------------------------------------------------------------

def determine_quality(w, h):
    if not w or not h:
        return 'medium'
    max_d = max(w, h)
    min_d = min(w, h)
    if max_d >= 3840 or min_d >= 2160:
        return 'ultra'
    if max_d >= 1920 or min_d >= 1080:
        return 'high'
    if max_d >= 1280 or min_d >= 720:
        return 'medium'
    return 'low'

def process_image(src_path, dst_thumb_path, max_w=150, max_h=150):
    """
    Returns dict: {'width': int, 'height': int, 'mime': str, 'ext': str}
    and creates a thumbnail at dst_thumb_path.
    Supports images (JPG, PNG, GIF, WebP) via PHP GD and videos (MP4, WebM) via ffmpeg/ffprobe.
    """
    ext = os.path.splitext(src_path)[1].lstrip('.').lower()
    if ext in ['mp4', 'webm']:
        w, h = 0, 0
        try:
            res = subprocess.run(
                ['ffprobe', '-v', 'error', '-select_streams', 'v:0',
                 '-show_entries', 'stream=width,height', '-of', 'json', src_path],
                capture_output=True, text=True
            )
            if res.returncode == 0:
                data = json.loads(res.stdout)
                streams = data.get('streams', [])
                if streams:
                    w = int(streams[0].get('width', 0))
                    h = int(streams[0].get('height', 0))
        except Exception:
            pass

        try:
            cmd = [
                'ffmpeg', '-i', src_path, '-ss', '00:00:00.000', '-vframes', '1',
                '-vf', f"scale='max({max_w},a*{max_w})':'max({max_h},{max_h}/a)',crop={max_w}:{max_h}",
                '-y', dst_thumb_path
            ]
            subprocess.run(cmd, capture_output=True, check=True)
        except Exception:
            pass

        return {
            'width': w,
            'height': h,
            'mime': f"video/{ext}",
            'ext': ext
        }

    php_code = """
    $src = $argv[1];
    $dst = $argv[2];
    $maxW = (int)$argv[3];
    $maxH = (int)$argv[4];

    $info = @getimagesize($src);
    if (!$info) {
        echo json_encode(['error' => 'invalid image file']);
        exit(1);
    }
    $w = $info[0];
    $h = $info[1];
    $mime = $info['mime'];
    $type = $info[2];

    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $ext = $extMap[$mime] ?? 'jpg';

    // Create thumbnail
    $ratio = min($maxW / $w, $maxH / $h);
    $nw = max(1, (int)round($w * $ratio));
    $nh = max(1, (int)round($h * $ratio));

    $im = match($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_GIF  => @imagecreatefromgif($src),
        IMAGETYPE_WEBP => @imagecreatefromwebp($src),
        default => null,
    };
    if ($im) {
        $thumb = imagecreatetruecolor($nw, $nh);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        imagecopyresampled($thumb, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        match($type) {
            IMAGETYPE_JPEG => imagejpeg($thumb, $dst, 85),
            IMAGETYPE_PNG  => imagepng($thumb, $dst, 6),
            IMAGETYPE_GIF  => imagegif($thumb, $dst),
            IMAGETYPE_WEBP => imagewebp($thumb, $dst, 85),
            default => null,
        };
        imagedestroy($thumb);
        imagedestroy($im);
    }

    echo json_encode(['width' => $w, 'height' => $h, 'mime' => $mime, 'ext' => $ext]);
    """
    res = subprocess.run(['php', '-r', php_code, src_path, dst_thumb_path, str(max_w), str(max_h)],
                         capture_output=True, text=True)
    if res.returncode == 0 and res.stdout.strip():
        data = json.loads(res.stdout.strip())
        if 'error' not in data:
            return data
    raise RuntimeError(f"Failed to process image: {res.stderr or res.stdout}")

# -----------------------------------------------------------------------------
# Scraper & Downloader
# -----------------------------------------------------------------------------

class RealbooruDownloader:
    BASE_URL = "https://realbooru.com/index.php"
    USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

    def __init__(self, tag="femboy", extra_tag="realbooru", limit=0, threads=10, db_path="data/libooru.db", data_dir="data", max_consecutive_skips=100, api_url="http://localhost:3000/api/v1", api_key="", use_api=True, reset_cache=False):
        self.tag = tag
        self.extra_tag = extra_tag
        self.limit = limit
        self.threads = threads
        self.db_path = db_path
        self.data_dir = data_dir
        self.max_consecutive_skips = max_consecutive_skips
        self.api_url = api_url
        self.api_key = api_key
        self.use_api = use_api
        self.uploads_dir = os.path.join(data_dir, "uploads")
        self.thumbs_dir = os.path.join(data_dir, "thumbs")
        # Path for scraper cache
        self.cache_path = os.path.join(data_dir, "scraper_cache.json")
        # Optionally reset cache
        if reset_cache and os.path.exists(self.cache_path):
            os.remove(self.cache_path)
        # Load last processed pid from cache if available
        self.last_pid = 0
        if os.path.exists(self.cache_path):
            try:
                with open(self.cache_path, "r") as f:
                    data = json.load(f)
                    self.last_pid = data.get("last_pid", 0)
            except Exception:
                self.last_pid = 0
        if not self.use_api:
            os.makedirs(self.uploads_dir, exist_ok=True)
            os.makedirs(self.thumbs_dir, exist_ok=True)
        self.db = Database(self.db_path)

    def _fetch_html(self, url, retries=5):
        headers = {
            'User-Agent': self.USER_AGENT,
            'Referer': 'https://realbooru.com/'
        }
        for attempt in range(retries):
            try:
                req = urllib.request.Request(url, headers=headers)
                with urllib.request.urlopen(req, timeout=20) as resp:
                    return resp.read().decode('utf-8', errors='ignore')
            except Exception as e:
                if attempt == retries - 1:
                    raise e
                time.sleep(2 * (attempt + 1))

    def _download_bytes(self, url, retries=5):
        headers = {
            'User-Agent': self.USER_AGENT,
            'Referer': 'https://realbooru.com/'
        }
        for attempt in range(retries):
            try:
                req = urllib.request.Request(url, headers=headers)
                with urllib.request.urlopen(req, timeout=30) as resp:
                    return resp.read()
            except Exception as e:
                if attempt == retries - 1:
                    raise e
                time.sleep(2 * (attempt + 1))

    def get_post_ids(self, pid=0, retries=3):
        url = f"{self.BASE_URL}?page=post&s=list&tags={urllib.parse.quote_plus(self.tag)}&pid={pid}"
        for attempt in range(retries):
            try:
                html = self._fetch_html(url)
                ids = re.findall(r'page=post&(?:amp;)?s=view&(?:amp;)?id=(\d+)', html)
                seen = set()
                unique_ids = []
                for post_id in ids:
                    if post_id not in seen:
                        seen.add(post_id)
                        unique_ids.append(post_id)
                return unique_ids
            except Exception as e:
                if attempt == retries - 1:
                    print(f"  [Ostrzeżenie] Nie udało się pobrać strony pid={pid} po {retries} próbach: {e}")
                    raise e
                time.sleep(2 * (attempt + 1))

    def get_post_detail(self, post_id):
        url = f"{self.BASE_URL}?page=post&s=view&id={post_id}"
        html = self._fetch_html(url)

        img_match = (re.search(r'id=[\"\']image[\"\'][^>]+src=[\"\']([^\"\']+)[\"\']', html) or
                     re.search(r'src=[\"\']([^\"\']+)[\"\'][^>]+id=[\"\']image[\"\']', html) or
                     re.search(r'<source\s+[^>]*src=[\"\']([^\"\']+)[\"\']', html) or
                     re.search(r'<video\s+[^>]*src=[\"\']([^\"\']+)[\"\']', html))
        if not img_match:
            return None

        img_url = img_match.group(1)
        if img_url.startswith('//'):
            img_url = 'https:' + img_url
        elif img_url.startswith('/'):
            img_url = 'https://realbooru.com' + img_url

        # Normalize redundant slashes in domain URL
        img_url = re.sub(r'^(https?://realbooru\.com)/+', r'\1/', img_url)

        tags = re.findall(r'<a class=[\"\'](?:tag-type-[^\"\']+|model)[\"\'] href=[\"\'][^\"\']*tags=([^\"\'&>]+)', html)
        tags = [urllib.parse.unquote(t) for t in tags]

        # Explicitly ensure the extra_tag ('realbooru') is added
        if self.extra_tag and self.extra_tag not in tags:
            tags.append(self.extra_tag)

        rating = 'q'
        rating_match = re.search(r'Rating:\s*([A-Za-z]+)', html)
        if rating_match:
            r_str = rating_match.group(1).lower()
            if 'safe' in r_str: rating = 's'
            elif 'explicit' in r_str: rating = 'e'
            elif 'questionable' in r_str: rating = 'q'

        return {
            'img_url': img_url,
            'tags': tags,
            'rating': rating,
            'source': f"https://realbooru.com/index.php?page=post&s=view&id={post_id}",
            'title': f"Realbooru #{post_id}"
        }

    def _process_post(self, post_id):
        try:
            if self.db.has_post_id(post_id):
                return ('skipped', post_id, f"ID #{post_id}")

            detail = self.get_post_detail(post_id)
            if not detail:
                return ('error', post_id, "Nie udało się pobrać szczegółów posta (brak elementu #image/video)")

            img_url = detail['img_url']
            img_data = self._download_bytes(img_url)
            if not img_data:
                return ('error', post_id, "Pusta odpowiedź podczas pobierania pliku")

            md5_hash = hashlib.md5(img_data).hexdigest()

            if self.db.has_post_md5(md5_hash):
                return ('skipped', post_id, md5_hash)

            ext = img_url.split('.')[-1].split('?')[0].lower()
            if ext not in ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm']:
                ext = 'jpg'
            if ext == 'jpeg':
                ext = 'jpg'

            filename = f"{md5_hash}.{ext}"

            if self.use_api:
                mime_map = {
                    'jpg': 'image/jpeg',
                    'png': 'image/png',
                    'gif': 'image/gif',
                    'webp': 'image/webp',
                    'mp4': 'video/mp4',
                    'webm': 'video/webm',
                }
                mime = mime_map.get(ext, f"image/{ext}")

                import requests
                api_endpoint = f"{self.api_url.rstrip('/')}/posts"
                headers = {'X-API-Key': self.api_key}
                files = {'file': (filename, img_data, mime)}
                data = {
                    'tags': ' '.join(detail['tags']),
                    'rating': detail['rating'],
                    'source': detail['source'],
                    'title': detail['title']
                }

                res = requests.post(api_endpoint, headers=headers, files=files, data=data, timeout=60)
                if res.status_code == 201:
                    resp_json = res.json()
                    db_id = resp_json.get('id', 0)
                    self.db.existing_md5s.add(md5_hash)
                    self.db.existing_post_ids.add(str(post_id))
                    return ('downloaded', post_id, db_id, filename, detail['tags'])
                elif res.status_code == 409:
                    self.db.existing_md5s.add(md5_hash)
                    self.db.existing_post_ids.add(str(post_id))
                    return ('skipped', post_id, md5_hash)
                else:
                    return ('error', post_id, f"Błąd API HTTP {res.status_code}: {res.text.strip()}")
            else:
                upload_path = os.path.join(self.uploads_dir, filename)

                if ext in ['mp4', 'webm']:
                    thumb_filename = f"{md5_hash}.jpg"
                else:
                    thumb_filename = filename

                thumb_path = os.path.join(self.thumbs_dir, thumb_filename)

                with open(upload_path, 'wb') as f:
                    f.write(img_data)

                img_info = process_image(upload_path, thumb_path)
                filesize = len(img_data)

                post_record = {
                    'filename': filename,
                    'ext': img_info.get('ext', ext),
                    'mime': img_info.get('mime', f"image/{ext}"),
                    'filesize': filesize,
                    'width': img_info.get('width'),
                    'height': img_info.get('height'),
                    'md5': md5_hash,
                    'rating': detail['rating'],
                    'source': detail['source'],
                    'title': detail['title']
                }

                db_id = self.db.insert_post(post_record, detail['tags'])
                return ('downloaded', post_id, db_id, filename, detail['tags'])
        except Exception as e:
            return ('error', post_id, str(e))

    def download(self):
        print(f"=== Realbooru Downloader ===")
        print(f"Tag szukany : '{self.tag}'")
        print(f"Tag dodatkowy: '{self.extra_tag}'")
        print(f"Baza danych  : '{self.db_path}'")
        if self.use_api:
            print(f"Tryb zapisu  : API Libooru ({self.api_url})")
            print(f"Klucz API    : {self.api_key[:6]}...{self.api_key[-4:]}")
        else:
            print(f"Tryb zapisu  : Bezpośredni zapis do pliku ({self.data_dir})")
        print(f"Limit postów : {self.limit if self.limit > 0 else 'Brak limitu (wszystkie strony)'}")
        print(f"Wątki (threads): {self.threads}")
        print(f"Max pominięć : {self.max_consecutive_skips if self.max_consecutive_skips > 0 else 'Brak (skanuj wszystko)'}\n")

        pid = 0
        downloaded_count = 0
        skipped_count = 0
        error_count = 0
        submitted_ids = set()
        consecutive_empty = 0
        consecutive_skips = 0
        stop_requested = False

        with ThreadPoolExecutor(max_workers=self.threads) as executor:
            while True:
                # Delikatny odstęp czasowy (100ms) aby uniknąć bloku/rate-limit ze strony serwera przy szybkim pomijaniu
                time.sleep(0.1)

                try:
                    post_ids = self.get_post_ids(pid)
                except Exception as e:
                    print(f"[Błąd] Błąd sieci podczas pobierania pid={pid}: {e}. Pomijanie i próba następnego pid.")
                    pid += 42
                    consecutive_empty += 1
                    if consecutive_empty >= 3:
                        print("Wystąpiło zbyt wiele błędów sieciowych z rzędu. Przerwano pobieranie.")
                        break
                    continue

                if not post_ids:
                    consecutive_empty += 1
                    if consecutive_empty >= 2:
                        print("Brak kolejnych postów na Realbooru.")
                        break
                    print(f"Strona pid={pid}: brak postów, próba pid={pid + 42}...")
                    pid += 42
                    continue

                consecutive_empty = 0
                new_post_ids = [p_id for p_id in post_ids if p_id not in submitted_ids]

                if not new_post_ids:
                    # Wszystkie ID z tej strony były już przetworzone w tej sesji —
                    # traktuj całą stronę jako pominięcia, żeby consecutive_skips działał poprawnie
                    page_skip_count = len(post_ids)
                    consecutive_skips += page_skip_count
                    skipped_count += page_skip_count
                    print(f"Strona pid={pid}: znaleziono {len(post_ids)} postów (nowych: 0), wszystkie już przetworzone.")
                    if self.max_consecutive_skips > 0 and consecutive_skips >= self.max_consecutive_skips:
                        print(f"\nPominięto kolejno {consecutive_skips} postów (istnieją już w bazie). Zatrzymywanie pobierania.")
                        print("(Użyj --max-consecutive-skips 0, aby skanować wszystkie strony bez zatrzymywania).")
                        stop_requested = True
                    pid += 42
                    if stop_requested:
                        break
                    continue

                print(f"Strona pid={pid}: znaleziono {len(post_ids)} postów (nowych: {len(new_post_ids)}).")

                futures = []
                for post_id in new_post_ids:
                    submitted_ids.add(post_id)
                    if self.limit > 0 and len(submitted_ids) > self.limit:
                        break
                    futures.append(executor.submit(self._process_post, post_id))

                for future in as_completed(futures):
                    res = future.result()
                    status = res[0]
                    if status == 'downloaded':
                        _, post_id, db_id, filename, tags = res
                        downloaded_count += 1
                        consecutive_skips = 0
                        print(f"  [{downloaded_count}] Pobrano post #{post_id} -> ID w bazie: {db_id} ({filename})")
                        print(f"      Tagi: {', '.join(tags[:8])}{'...' if len(tags) > 8 else ''}")
                    elif status == 'skipped':
                        _, post_id, md5_or_id = res
                        skipped_count += 1
                        consecutive_skips += 1
                        print(f"  [Pomiń] Post #{post_id} ({md5_or_id}) już istnieje w bazie.")
                    elif status == 'error':
                        _, post_id, err_msg = res
                        error_count += 1
                        print(f"  [Błąd] Nie udało się przetworzyć posta #{post_id}: {err_msg}")

                if self.limit > 0 and len(submitted_ids) >= self.limit:
                    print(f"\nOsiągnięto limit pobierania ({self.limit} postów).")
                    break

                if self.max_consecutive_skips > 0 and consecutive_skips >= self.max_consecutive_skips:
                    print(f"\nPominięto kolejno {consecutive_skips} postów (istnieją już w bazie). Zatrzymywanie pobierania.")
                    print("(Użyj --max-consecutive-skips 0, aby skanować wszystkie strony bez zatrzymywania).")
                    break

                pid += 42

        print(f"\nZakończono! Pobrano: {downloaded_count}, Pominięto (duplikaty): {skipped_count}, Błędy: {error_count}.")

def main():
    parser = argparse.ArgumentParser(description="Downloader postów z Realbooru.com do bazy danych")
    parser.add_argument("--tag", default="femboy", help="Tag do wyszukania na Realbooru (domyślnie: femboy)")
    parser.add_argument("--extra-tag", default="realbooru", help="Dodatkowy tag dopisywany do każdego posta (domyślnie: realbooru)")
    parser.add_argument("--limit", type=int, default=0, help="Liczba postów do pobrania (domyślnie: 0 = pobierz wszystkie)")
    parser.add_argument("--threads", type=int, default=10, help="Liczba wątków (domyślnie: 10)")
    parser.add_argument("--db", default="data/libooru.db", help="Ścieżka do bazy SQLite (domyślnie: data/libooru.db)")
    parser.add_argument("--data-dir", default="data", help="Katalog na pobrane pliki i miniaturki w trybie bezpośrednim (domyślnie: data)")
    parser.add_argument("--max-consecutive-skips", type=int, default=100, help="Maksymalna liczba pominiętych postów z rzędu przed zatrzymaniem pobierania (domyślnie: 100, 0 = wyłącz)")
    parser.add_argument("--api-url", default="http://localhost:3000/api/v1", help="URL do API Libooru (domyślnie: http://localhost:3000/api/v1)")
    parser.add_argument("--api-key", default=os.environ.get("LIBOORU_API_KEY", ""), help="Klucz API użytkownika Libooru (lub zmienna LIBOORU_API_KEY)")
    parser.add_argument("--no-api", action="store_true", help="Wyłącz upload przez API i zapisuj pliki lokalnie/bezpośrednio do bazy")

    args = parser.parse_args()
    if not args.no_api and not args.api_key:
        parser.error("--api-key lub zmienna LIBOORU_API_KEY jest wymagana w trybie API")

    downloader = RealbooruDownloader(
        tag=args.tag,
        extra_tag=args.extra_tag,
        limit=args.limit,
        threads=args.threads,
        db_path=args.db,
        data_dir=args.data_dir,
        max_consecutive_skips=args.max_consecutive_skips,
        api_url=args.api_url,
        api_key=args.api_key,
        use_api=not args.no_api
    )
    downloader.download()

if __name__ == "__main__":
    main()
