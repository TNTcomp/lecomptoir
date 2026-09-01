<?php
// ============================================
// FLOTTE PRO - Configuration
// ============================================

// Chemin absolu vers le dossier data (protege)
define('DATA_DIR', __DIR__ . '/../data/');

// Cle secrete pour les sessions (A PERSONNALISER)
define('SECRET_KEY', 'Fl0ttePr0_2026_Ch4ngeM3!');

// Nom de l'entreprise cliente (A PERSONNALISER lors de l'installation)
define('CLIENT_NAME', 'Nos Spare Parts');

// Session timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Headers de securite
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// CORS - autoriser uniquement le meme domaine
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Gestion preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Demarrage session
session_start();

// Session timeout check
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

/**
 * Reponse JSON uniforme
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Verifier si l'utilisateur est authentifie
 */
function requireAuth($minRole = 'chauffeur') {
    if (!isset($_SESSION['user'])) {
        jsonResponse(['error' => 'Non authentifie', 'code' => 'AUTH_REQUIRED'], 401);
    }
    $user = $_SESSION['user'];
    $roles = ['chauffeur' => 1, 'comptable' => 2, 'manager' => 3, 'admin' => 4];
    $userLevel = isset($roles[$user['role']]) ? $roles[$user['role']] : 0;
    $requiredLevel = isset($roles[$minRole]) ? $roles[$minRole] : 0;
    if ($userLevel < $requiredLevel) {
        jsonResponse(['error' => 'Acces refuse', 'code' => 'FORBIDDEN', 'required' => $minRole], 403);
    }
    return $user;
}

/**
 * Lire un fichier JSON
 */
function readJSON($filename) {
    $path = DATA_DIR . $filename;
    if (!file_exists($path)) {
        return null;
    }
    $content = file_get_contents($path);
    return json_decode($content, true);
}

/**
 * Ecrire un fichier JSON (avec verrouillage)
 */
function writeJSON($filename, $data) {
    $path = DATA_DIR . $filename;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    // Verrouillage exclusif pendant l'ecriture
    $fp = fopen($path, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return true;
}

/**
 * Generer un identifiant unique
 */
function genId() {
    return uniqid('', true);
}

/**
 * Generer un numero de ticket
 */
function nextTicket($db, $type) {
    $prefixes = ['filter' => 'FLT', 'oil' => 'HUI', 'car' => 'CAR'];
    $prefix = isset($prefixes[$type]) ? $prefixes[$type] : 'TCK';
    $count = isset($db['ticketCounters'][$type]) ? $db['ticketCounters'][$type] : 0;
    $count++;
    $db['ticketCounters'][$type] = $count;
    return $prefix . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/**
 * Hash mot de passe
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verifier mot de passe
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
