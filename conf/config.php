<?php  // Moodle configuration file (généré par moodle-buildpack pour Scalingo)

// Toute la configuration est lue depuis les variables d'environnement.
// Pour des réglages supplémentaires, ajoutez un fichier config.local.php à la
// racine de votre application : il est inclus juste avant lib/setup.php.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$moodleenv = static function (string $name, $default = null) {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
};

//=========================================================================
// Base de données PostgreSQL (addon Scalingo)
//=========================================================================
$databaseurl = $moodleenv('SCALINGO_POSTGRESQL_URL');
if ($databaseurl === null) {
    throw new RuntimeException('SCALINGO_POSTGRESQL_URL is not defined: add a PostgreSQL addon to the application.');
}

$db = parse_url($databaseurl);
if ($db === false || empty($db['host'])) {
    throw new RuntimeException('SCALINGO_POSTGRESQL_URL is not a valid URL.');
}
parse_str($db['query'] ?? '', $dbquery);

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = $db['host'];
$CFG->dbname    = ltrim(rawurldecode($db['path'] ?? ''), '/');
$CFG->dbuser    = rawurldecode($db['user'] ?? '');
$CFG->dbpass    = rawurldecode($db['pass'] ?? '');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = [
    'dbpersist'      => false,
    'dbport'         => $db['port'] ?? '',
    'connecttimeout' => 10,
];
if (!empty($dbquery['sslmode'])) {
    $CFG->dboptions['ssl'] = $dbquery['sslmode'];
}

//=========================================================================
// URL et chemins
//=========================================================================
$wwwroot = $moodleenv('MOODLE_WWWROOT');
if ($wwwroot === null) {
    throw new RuntimeException('MOODLE_WWWROOT is not defined (e.g. https://my-app.osc-fr1.scalingo.io).');
}
$CFG->wwwroot = rtrim($wwwroot, '/');

// TLS est terminé par le routeur Scalingo.
$CFG->sslproxy = str_starts_with($CFG->wwwroot, 'https://');

// Le routeur Moodle est configuré dans Nginx (conf/nginx/moodle.conf.erb).
$CFG->routerconfigured = true;

// Le disque des conteneurs Scalingo est éphémère : les fichiers déposés sont
// envoyés sur S3 (voir plus bas), moodledata ne contient sinon que des
// données temporaires (caches, fichiers temporaires).
$CFG->dataroot = '/app/moodledata';
if (!is_dir($CFG->dataroot)) {
    @mkdir($CFG->dataroot, 02777, true);
}
$CFG->localcachedir = sys_get_temp_dir() . '/moodle-localcache';

$CFG->admin                = 'admin';
$CFG->directorypermissions = 02777;
$CFG->pathtophp            = '/app/vendor/php/bin/php';
$CFG->preventexecpath      = true;

//=========================================================================
// Stockage des fichiers sur S3 (Scaleway Object Storage)
//=========================================================================
// tool_objectfs : les fichiers sont écrits en local puis envoyés sur S3 par la
// tâche push_objects_to_storage (lancée chaque minute par bin/moodle-web).
$s3bucket = $moodleenv('MOODLE_S3_BUCKET');
if ($s3bucket !== null) {
    $s3region = $moodleenv('MOODLE_S3_REGION', 'fr-par');

    $CFG->alternative_file_system_class = '\tool_objectfs\s3_file_system';
    $CFG->forced_plugin_settings['tool_objectfs'] = [
        'filesystem'       => '\tool_objectfs\s3_file_system',
        's3_bucket'        => $s3bucket,
        's3_region'        => $s3region,
        's3_base_url'      => $moodleenv('MOODLE_S3_ENDPOINT', "https://s3.{$s3region}.scw.cloud"),
        's3_key'           => $moodleenv('MOODLE_S3_ACCESS_KEY', ''),
        's3_secret'        => $moodleenv('MOODLE_S3_SECRET_KEY', ''),
        's3_usesdkcreds'   => 0,
        's3_bucket_acl'    => 'private',
        'key_prefix'       => $moodleenv('MOODLE_S3_KEY_PREFIX', ''),
        // Envoi de tous les fichiers dès que possible, puis suppression de la
        // copie locale après le délai de cohérence.
        'enabletasks'      => 1,
        'sizethreshold'    => 0,
        'minimumage'       => 0,
        'deletelocal'      => 1,
        'consistencydelay' => 600,
        'preferexternal'   => 0,
        'deleteexternal'   => 0,
    ];
}

//=========================================================================
// Sessions Redis (addon Scalingo)
//=========================================================================
$redisurl = $moodleenv('SCALINGO_REDIS_URL');
if ($redisurl !== null) {
    $redis = parse_url($redisurl);
    $CFG->session_handler_class = '\core\session\redis';
    $CFG->session_redis_host    = (($redis['scheme'] ?? '') === 'rediss' ? 'tls://' : '') . $redis['host'];
    $CFG->session_redis_port    = (int)($redis['port'] ?? 6379);
    $CFG->session_redis_auth    = isset($redis['pass']) ? rawurldecode($redis['pass']) : '';
    $CFG->session_redis_prefix  = 'moodle_sess_';
    $CFG->session_redis_acquire_lock_timeout = 120;
    $CFG->session_redis_lock_expire = 7200;
}

//=========================================================================
// E-mails
//=========================================================================
if ($moodleenv('MOODLE_SMTP_HOST') !== null) {
    $CFG->smtphosts    = $moodleenv('MOODLE_SMTP_HOST');           // host:port
    $CFG->smtpuser     = $moodleenv('MOODLE_SMTP_USER', '');
    $CFG->smtppass     = $moodleenv('MOODLE_SMTP_PASSWORD', '');
    $CFG->smtpsecure   = $moodleenv('MOODLE_SMTP_SECURE', '');      // '', 'tls' ou 'ssl'
    $CFG->smtpauthtype = $moodleenv('MOODLE_SMTP_AUTH_TYPE', 'LOGIN');
}
if ($moodleenv('MOODLE_NOREPLY_ADDRESS') !== null) {
    $CFG->noreplyaddress = $moodleenv('MOODLE_NOREPLY_ADDRESS');
}

//=========================================================================
// Debug
//=========================================================================
if (filter_var($moodleenv('MOODLE_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    $CFG->debug          = (E_ALL); // DEBUG_DEVELOPER
    $CFG->debugdisplay   = 1;
    $CFG->debugstringids = 1;
    $CFG->passwordpolicy = 0;
}

//=========================================================================
// Surcharges locales
//=========================================================================
if (file_exists(__DIR__ . '/config.local.php')) {
    require(__DIR__ . '/config.local.php');
}

unset($moodleenv, $databaseurl, $db, $dbquery, $wwwroot, $s3bucket, $s3region, $redisurl, $redis);

require_once(__DIR__ . '/lib/setup.php'); // Do not edit

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
