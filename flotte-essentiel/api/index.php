<?php
// ============================================
// FLOTTE PRO - API principale (CRUD)
// ============================================

require_once 'config.php';

// Routeur principal
$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

// Lecture du corps JSON pour POST
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!$input && $method === 'POST') {
    $input = $_POST;
}

switch ($action) {

    // ========================================
    // AUTHENTIFICATION
    // ========================================
    case 'login':
        if ($method !== 'POST') jsonResponse(['error' => 'Methode non autorisee'], 405);
        
        $username = isset($input['username']) ? trim($input['username']) : '';
        $password = isset($input['password']) ? $input['password'] : '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['error' => 'Nom d\'utilisateur et mot de passe requis'], 400);
        }
        
        $users = readJSON('users.json');
        if (!$users) {
            jsonResponse(['error' => 'Aucun utilisateur configure. Lancez l\'installation.'], 500);
        }
        
        foreach ($users as $user) {
            if ($user['username'] === $username && $user['active']) {
                if (verifyPassword($password, $user['password'])) {
                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'name' => $user['name'],
                        'role' => $user['role']
                    ];
                    jsonResponse([
                        'success' => true,
                        'user' => $_SESSION['user'],
                        'redirect' => 'index.html'
                    ]);
                }
            }
        }
        jsonResponse(['error' => 'Identifiants incorrects'], 401);
        break;

    case 'logout':
        session_unset();
        session_destroy();
        jsonResponse(['success' => true]);
        break;

    case 'me':
        if (!isset($_SESSION['user'])) {
            jsonResponse(['error' => 'Non authentifie'], 401);
        }
        jsonResponse(['user' => $_SESSION['user']]);
        break;

    // ========================================
    // GESTION UTILISATEURS
    // ========================================
    case 'users_list':
        $me = requireAuth('manager');
        $users = readJSON('users.json') ?: [];
        // Masquer les mots de passe
        $safe = array_map(function($u) {
            unset($u['password']);
            return $u;
        }, $users);
        jsonResponse(['users' => $safe]);
        break;

    case 'users_create':
        $me = requireAuth('manager');
        if ($method !== 'POST') jsonResponse(['error' => 'Methode non autorisee'], 405);
        
        $users = readJSON('users.json') ?: [];
        $newUser = [
            'id' => genId(),
            'username' => trim($input['username']),
            'password' => hashPassword($input['password']),
            'name' => trim($input['name']),
            'role' => $input['role'],
            'active' => true,
            'created' => date('c')
        ];
        
        // Verifier doublon
        foreach ($users as $u) {
            if ($u['username'] === $newUser['username']) {
                jsonResponse(['error' => 'Ce nom d\'utilisateur existe deja'], 409);
            }
        }
        // Limite Formule 1: 3 utilisateurs max
        if (count($users) >= MAX_USERS) {
            jsonResponse(['error' => 'Limite de ' . MAX_USERS . ' utilisateurs atteinte. Upgrade vers Flotte Pro pour plus d\'utilisateurs.'], 403);
        }
        
        $validRoles = ['chauffeur', 'comptable', 'manager', 'admin'];
        if (!in_array($newUser['role'], $validRoles)) {
            jsonResponse(['error' => 'Role invalide'], 400);
        }
        
        $users[] = $newUser;
        writeJSON('users.json', $users);
        unset($newUser['password']);
        jsonResponse(['success' => true, 'user' => $newUser]);
        break;

    case 'users_update':
        $me = requireAuth('manager');
        if ($method !== 'POST') jsonResponse(['error' => 'Methode non autorisee'], 405);
        
        $users = readJSON('users.json') ?: [];
        $updated = false;
        foreach ($users as &$u) {
            if ($u['id'] === $input['id']) {
                $u['name'] = isset($input['name']) ? trim($input['name']) : $u['name'];
                $u['role'] = isset($input['role']) ? $input['role'] : $u['role'];
                $u['active'] = isset($input['active']) ? (bool)$input['active'] : $u['active'];
                if (!empty($input['password'])) {
                    $u['password'] = hashPassword($input['password']);
                }
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            writeJSON('users.json', $users);
            jsonResponse(['success' => true]);
        }
        jsonResponse(['error' => 'Utilisateur non trouve'], 404);
        break;

    case 'users_delete':
        $me = requireAuth('admin');
        if ($method !== 'POST') jsonResponse(['error' => 'Methode non autorisee'], 405);
        
        $users = readJSON('users.json') ?: [];
        $users = array_values(array_filter($users, function($u) use ($input) {
            return $u['id'] !== $input['id'];
        }));
        writeJSON('users.json', $users);
        jsonResponse(['success' => true]);
        break;

    // ========================================
    // DONNEES FLOTTE
    // ========================================
    case 'get_db':
        $me = requireAuth('chauffeur');
        $db = readJSON('fleet.json');
        if (!$db) {
            jsonResponse(['error' => 'Base de donnees flotte non initialisee'], 500);
        }
        // Masquer selon le role
        if ($me['role'] === 'chauffeur') {
            // Chauffeur: seulement les donnees de saisie (pas le cout total visible)
        }
        jsonResponse(['db' => $db, 'user' => $me]);
        break;

    case 'save_db':
        $me = requireAuth('manager');
        if ($method !== 'POST') jsonResponse(['error' => 'Methode non autorisee'], 405);
        
        $db = $input['db'];
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true]);
        break;

    // ========================================
    // OPERATIONS INDIVIDUELLES
    // ========================================
    case 'add_truck':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        if (count($db['trucks']) >= MAX_TRUCKS) {
            jsonResponse(['error' => 'Limite de ' . MAX_TRUCKS . ' camions atteinte. Contactez votre fournisseur pour upgrade vers Flotte Pro.'], 403);
        }
        $truck = $input['truck'];
        $truck['id'] = genId();
        $db['trucks'][] = $truck;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'truck' => $truck]);
        break;

    case 'add_fuel':
        $me = requireAuth('chauffeur');
        $db = readJSON('fleet.json') ?: newDB();
        $entry = $input['entry'];
        $entry['id'] = genId();
        $entry['createdBy'] = $me['username'];
        $entry['ticketNo'] = nextTicket($db, 'car');
        $db['fuel'][] = $entry;
        $db['ticketCounters'] = isset($db['ticketCounters']) ? $db['ticketCounters'] : [];
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'entry' => $entry]);
        break;

    case 'add_oil':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        $entry = $input['entry'];
        $entry['id'] = genId();
        $entry['createdBy'] = $me['username'];
        $entry['ticketNo'] = nextTicket($db, 'oil');
        $db['oilChanges'][] = $entry;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'entry' => $entry]);
        break;

    case 'add_filter':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        $entry = $input['entry'];
        $entry['id'] = genId();
        $entry['createdBy'] = $me['username'];
        $entry['ticketNo'] = nextTicket($db, 'filter');
        $db['filterChanges'][] = $entry;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'entry' => $entry]);
        break;

    case 'add_mileage':
        $me = requireAuth('chauffeur');
        $db = readJSON('fleet.json') ?: newDB();
        $entry = $input['entry'];
        $entry['id'] = genId();
        $entry['createdBy'] = $me['username'];
        $db['mileage'][] = $entry;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'entry' => $entry]);
        break;

    case 'update_status':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        foreach ($db['trucks'] as &$t) {
            if ($t['ref'] === $input['ref']) {
                $t['statut'] = $input['statut'];
                $t['statusDate'] = ($input['statut'] === 'Maintenance' || $input['statut'] === 'En panne') ? $input['date'] : '';
                $t['statusReason'] = isset($input['reason']) ? $input['reason'] : '';
                if ($input['statut'] === 'Actif' && !empty($t['statusDate'])) {
                    $t['lastDowntime'] = round((strtotime($input['date']) - strtotime($t['statusDate'])) / 86400) . ' jours';
                }
                break;
            }
        }
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true]);
        break;

    // ========================================
    // DOCUMENTS (Assurance, Visite Technique, Carte Grise)
    // ========================================
    case 'add_document':
        jsonResponse(['error' => 'Module Documents non disponible en Formule 1.'], 403);
        break;

    case 'delete_document':
        jsonResponse(['error' => 'Module Documents non disponible en Formule 1.'], 403);
        break;

    // ========================================
    // PNEUS
    // ========================================
    case 'add_tire':
        jsonResponse(['error' => 'Module Pneus non disponible en Formule 1.'], 403);
        break;

    case 'update_tire':
        jsonResponse(['error' => 'Module Pneus non disponible en Formule 1.'], 403);
        break;

    // ========================================
    // AFFECTATION CHAUFFEUR
    // ========================================
    case 'assign_driver':
        jsonResponse(['error' => 'Module Affectation non disponible en Formule 1.'], 403);
        break;

    // ========================================
    // PARAMETRES (devise, seuils alertes)
    // ========================================
    case 'get_settings':
        jsonResponse(['settings' => ['currency' => 'TND', 'oilInterval' => 15000, 'filterInterval' => 30000]]);
        break;

    case 'save_settings':
        jsonResponse(['error' => 'Configuration non disponible en Formule 1.'], 403);
        break;

    // ========================================
    // PHOTOS (upload base64)
    // ========================================
    case 'add_photo':
        jsonResponse(['error' => 'Module Photos non disponible en Formule 1.'], 403);
        break;

    case 'get_photos':
        jsonResponse(['error' => 'Module Photos non disponible en Formule 1.'], 403);
        break;

    case 'delete_photo':
        jsonResponse(['error' => 'Module Photos non disponible en Formule 1.'], 403);
        break;

    // ========================================
    // BUDGET / TABLEAU DE BORD FINANCE
    // ========================================
    case 'add_budget':
        jsonResponse(['error' => 'Module Finance non disponible en Formule 1.'], 403);
        break;

    case 'get_budget_report':
        jsonResponse(['error' => 'Module Finance non disponible en Formule 1.'], 403);
        break;

    // ========================================
    // ALERTES WHATSAPP
    // ========================================
    case 'get_alerts_whatsapp':
        jsonResponse(['error' => 'Alertes WhatsApp non disponibles en Formule 1.'], 403);
        break;

    case 'save_whatsapp_config':
        jsonResponse(['error' => 'Alertes WhatsApp non disponibles en Formule 1.'], 403);
        break;

    // ========================================
    // EXPORT COMPTABLE
    // ========================================
    case 'export_csv':
        $me = requireAuth('comptable');
        $db = readJSON('fleet.json') ?: newDB();
        $type = isset($_GET['type']) ? $_GET['type'] : 'all';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_flotte_' . date('Y-m-d') . '.csv"');
        
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 pour Excel
        
        fputcsv($out, ['Type', 'Ticket', 'Date', 'Camion', 'Detail', 'Quantite', 'Prix Unit.', 'Total (TND)', 'Fournisseur', 'Saisi par']);
        
        if ($type === 'all' || $type === 'fuel') {
            foreach ($db['fuel'] as $f) {
                fputcsv($out, ['Carburant', $f['ticketNo'] ?? '', $f['date'], $f['truckRef'], 
                    $f['type'] ?? 'Gasoil', $f['liters'] ?? 0, $f['pricePerL'] ?? 0,
                    ($f['liters'] ?? 0) * ($f['pricePerL'] ?? 0), $f['station'] ?? '', $f['createdBy'] ?? '']);
            }
        }
        if ($type === 'all' || $type === 'oil') {
            foreach ($db['oilChanges'] as $o) {
                fputcsv($out, ['Vidange', $o['ticketNo'] ?? '', $o['date'], $o['truckRef'],
                    ($o['oilType'] ?? '') . ' ' . ($o['oilBrand'] ?? ''), $o['liters'] ?? 0, $o['pricePerL'] ?? 0,
                    ($o['liters'] ?? 0) * ($o['pricePerL'] ?? 0), $o['supplier'] ?? '', $o['createdBy'] ?? '']);
            }
        }
        if ($type === 'all' || $type === 'filter') {
            foreach ($db['filterChanges'] as $f) {
                fputcsv($out, ['Filtre', $f['ticketNo'] ?? '', $f['date'], $f['truckRef'],
                    ($f['filterType'] ?? '') . ' ' . ($f['filterRef'] ?? ''), $f['qty'] ?? 1, $f['price'] ?? 0,
                    ($f['qty'] ?? 0) * ($f['price'] ?? 0), $f['supplier'] ?? '', $f['createdBy'] ?? '']);
            }
        }
        if ($type === 'all' || $type === 'tire') {
            foreach (($db['tires'] ?? []) as $t) {
                fputcsv($out, ['Pneu', 'PNE-' . ($t['id'] ?? ''), $t['installDate'] ?? '', $t['truckRef'] ?? '',
                    ($t['brand'] ?? '') . ' ' . ($t['size'] ?? '') . ' ' . ($t['position'] ?? ''), 1, $t['price'] ?? 0,
                    $t['price'] ?? 0, $t['supplier'] ?? '', $t['createdBy'] ?? '']);
            }
        }
        fclose($out);
        exit;
        break;

    // ========================================
    // INSTALLATION
    // ========================================
    case 'install':
        // Cree les fichiers de donnees initiaux si inexistants
        if (file_exists(DATA_DIR . 'users.json')) {
            jsonResponse(['error' => 'Deja installe. Supprimez les fichiers data/ pour reinstaller.'], 403);
        }
        
        // Admin par defaut
        $users = [[
            'id' => genId(),
            'username' => 'admin',
            'password' => hashPassword($input['admin_password'] ?? 'admin123'),
            'name' => $input['admin_name'] ?? 'Administrateur',
            'role' => 'admin',
            'active' => true,
            'created' => date('c')
        ]];
        writeJSON('users.json', $users);
        
        // Base de donnees flotte
        $db = newDB();
        writeJSON('fleet.json', $db);
        
        jsonResponse([
            'success' => true,
            'message' => 'Installation reussie. Connectez-vous avec admin / ' . ($input['admin_password'] ?? 'admin123')
        ]);
        break;

    case 'status':
        $installed = file_exists(DATA_DIR . 'users.json') && file_exists(DATA_DIR . 'fleet.json');
        jsonResponse([
            'installed' => $installed,
            'client' => CLIENT_NAME,
            'version' => '1.0'
        ]);
        break;

    default:
        jsonResponse(['error' => 'Action inconnue', 'available' => [
            'login', 'logout', 'me',
            'users_list', 'users_create', 'users_update', 'users_delete',
            'get_db', 'save_db',
            'add_truck', 'add_fuel', 'add_oil', 'add_filter', 'add_mileage',
            'update_status',
            'add_document', 'delete_document',
            'add_tire', 'update_tire',
            'add_photo', 'get_photos', 'delete_photo',
            'add_budget', 'get_budget_report',
            'get_alerts_whatsapp', 'save_whatsapp_config',
            'assign_driver',
            'get_settings', 'save_settings',
            'export_csv',
            'install', 'status'
        ]], 404);
}

function newDB() {
    return [
        'trucks' => [],
        'fuel' => [],
        'oilChanges' => [],
        'filterChanges' => [],
        'mileage' => [],
        'documents' => [],
        'tires' => [],
        'driverAssignments' => [],
        'photos' => [],
        'budgets' => [],
        'ticketCounters' => ['filter' => 0, 'oil' => 0, 'car' => 0]
    ];
}
