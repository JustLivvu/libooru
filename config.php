<?php
declare(strict_types=1);

define('LIBOORU_ROOT', __DIR__);
define('DATA_DIR',     LIBOORU_ROOT . '/data');
define('UPLOAD_DIR',   DATA_DIR . '/uploads');
define('THUMB_DIR',    DATA_DIR . '/thumbs');
define('SITE_ASSET_DIR', DATA_DIR . '/site-assets');
define('DB_PATH',      DATA_DIR . '/libooru.db');


define('SITE_NAME',    'Libooru');
define('SITE_BASE',    '');


define('SITE_URL',     rtrim((string)(getenv('LIBOORU_SITE_URL') ?: ''), '/'));
define('POSTS_PER_PAGE', 75);
define('THUMB_WIDTH',  450);
define('THUMB_HEIGHT', 450);


const ALLOWED_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'video/mp4'  => 'mp4',
    'video/x-m4v' => 'mp4',
    'video/webm' => 'webm',
    'video/quicktime' => 'mov',
    'video/x-quicktime' => 'mov',
    'application/quicktime' => 'mov',
];

// Canonical names for equivalent tags imported from different booru sites.
const TAG_ALIASES = [
    'digital_media_(artwork)' => 'artwork',
    'butt' => 'ass',
    'booty' => 'ass',
    'buttocks' => 'ass',
    'breast' => 'breasts',
    'boob' => 'breasts',
    'boobs' => 'breasts',
    'tit' => 'breasts',
    'tits' => 'breasts',
    'cock' => 'penis',
    'dick' => 'penis',
    'phallus' => 'penis',
    'testicle' => 'balls',
    'testicles' => 'balls',
    'anal_hole' => 'anus',
    'asshole' => 'anus',
    'brunette' => 'brown_hair',
    'blond' => 'blonde_hair',
    'blonde' => 'blonde_hair',
    'blond_hair' => 'blonde_hair',
    'redhead' => 'red_hair',
    'gray_hair' => 'grey_hair',
    'male_solo' => 'solo_male',
    'solo_man' => 'solo_male',
    'thigh-highs' => 'thighhighs',
    'thigh_highs' => 'thighhighs',
    'strap-on' => 'strapon',
    'strap_on' => 'strapon',
    'cum_shot' => 'cumshot',
    'doggy_style' => 'doggystyle',
    'doggy_position' => 'doggystyle',
    'missionary_position' => 'missionary',
    'reverse_cowgirl' => 'reverse_cowgirl_position',
    'hand_job' => 'handjob',
    'blow_job' => 'blowjob',
    'fellatio' => 'blowjob',
    'foot_job' => 'footjob',
    'tit_job' => 'titfuck',
    'paizuri' => 'titfuck',
    'jerking_off' => 'masturbation',
    'jacking_off' => 'masturbation',
    'analingus' => 'rimming',
    'rimjob' => 'rimming',
    'pussy_licking' => 'cunnilingus',
    'oral_sex' => 'oral',
    'vaginal_sex' => 'vaginal',
    'semen' => 'cum',
    'cum_on_face' => 'facial',
    'internal_cumshot' => 'cum_inside',
    'creampie' => 'cum_inside',
    'panty' => 'panties',
    'outdoors' => 'outside',
    'inside' => 'indoors',
    'transwoman' => 'trans_female',
    'trans_woman' => 'trans_female',
    'transman' => 'trans_male',
    'trans_man' => 'trans_male',
    'big_breasts' => 'large_breasts',
    'big_boobs' => 'large_breasts',
    'large_penis' => 'big_penis',
    'large_ass' => 'big_ass',
    'small_tits' => 'small_breasts',
    // Unambiguous spelling mistakes and malformed number tags.
    'pen8s' => 'penis',
    'mavel' => 'navel',
    'doldo' => 'dildo',
    'big_asz' => 'big_ass',
    'stockinga' => 'stockings',
    'crossdresset' => 'crossdresser',
    'syraight' => 'straight',
    'caninu' => 'canine',
    'whitr' => 'white',
    'pelfie' => 'selfie',
    'shabed' => 'shaved',
    'vaginal_penitration' => 'vaginal_penetration',
    'trap_pnly' => 'trap_only',
    'trap_obly' => 'trap_only',
    'parter_lips' => 'parted_lips',
    'keeth' => 'teeth',
    'hoddie' => 'hoodie',
    'enhibitionism' => 'exhibitionism',
    'died_hair' => 'dyed_hair',
    'craid' => 'braid',
    'woren' => 'women',
    'fut8nari' => 'futanari',
    'aninated' => 'animated',
    'birthnark' => 'birthmark',
    'aoffee' => 'coffee',
    'hogdogging' => 'hotdogging',
    'black_eyess' => 'black_eyes',
    'anuss' => 'anus',
    '1girls' => '1girl',
    '1boys' => '1boy',
    '2girl' => '2girls',
    '2boy' => '2boys',
    'small_breast' => 'small_breasts',
    'medium_breast' => 'medium_breasts',
    'large_breast' => 'large_breasts',
    // Stable spelling variants and direct synonyms.
    'grayscale' => 'greyscale',
    'gray_panties' => 'grey_panties',
    'multicolor_hair' => 'multicolored_hair',
    'boipussy' => 'boypussy',
    'shaven' => 'shaved',
    'selfpic' => 'selfie',
    'brasil' => 'brazil',
    'thicc' => 'thick',
];


define('MAX_FILE_SIZE', 100 * 1024 * 1024);
define('MAX_MEDIA_PIXELS', 20_000_000);
define('MEDIA_PROCESS_TIMEOUT', 30);



define('VIDEO_RATE_LIMIT_AFTER', 2 * 1024 * 1024);
define('VIDEO_RATE_LIMIT', 2560 * 1024);
define('S3_VIDEO_URL_TTL', 3600);
define('S3_THUMB_URL_TTL', 3600);


define('MAX_COMMENT_LENGTH', 4_000);
define('COMMENT_RATE_LIMIT', 10);
define('COMMENT_RATE_WINDOW', 60);


define('MAX_POST_REPORT_LENGTH', 1_000);
define('POST_REPORT_RATE_LIMIT', 5);
define('POST_REPORT_RATE_WINDOW', 3600);


define('API_KEY_HEADER', 'X-API-Key');
define('ACTIVITY_TIMEZONE', 'Europe/Prague');
