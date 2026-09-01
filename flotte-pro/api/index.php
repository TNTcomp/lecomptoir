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
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        $doc = $input['doc'];
        $doc['id'] = genId();
        $doc['createdBy'] = $me['username'];
        if (!isset($db['documents'])) $db['documents'] = [];
        $db['documents'][] = $doc;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'doc' => $doc]);
        break;

    case 'delete_document':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        if (!isset($db['documents'])) $db['documents'] = [];
        $db['documents'] = array_values(array_filter($db['documents'], function($d) use ($input) {
            return $d['id'] !== $input['id'];
        }));
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true]);
        break;

    // ========================================
    // PNEUS
    // ========================================
    case 'add_tire':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        $tire = $input['tire'];
        $tire['id'] = genId();
        $tire['createdBy'] = $me['username'];
        if (!isset($db['tires'])) $db['tires'] = [];
        $db['tires'][] = $tire;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'tire' => $tire]);
        break;

    case 'update_tire':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        if (!isset($db['tires'])) $db['tires'] = [];
        foreach ($db['tires'] as &$t) {
            if ($t['id'] === $input['id']) {
                $t['status'] = isset($input['status']) ? $input['status'] : $t['status'];
                $t['kmInstall'] = isset($input['kmInstall']) ? $input['kmInstall'] : $t['kmInstall'];
                $t['notes'] = isset($input['notes']) ? $input['notes'] : $t['notes'];
                break;
            }
        }
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true]);
        break;

    // ========================================
    // AFFECTATION CHAUFFEUR
    // ========================================
    case 'assign_driver':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        if (!isset($db['driverAssignments'])) $db['driverAssignments'] = [];
        $assign = $input['assignment'];
        $assign['id'] = genId();
        $assign['assignedBy'] = $me['username'];
        // Marquer les affectations precedentes du meme camion comme inactives
        foreach ($db['driverAssignments'] as &$a) {
            if ($a['truckRef'] === $assign['truckRef'] && $a['active']) {
                $a['active'] = false;
                $a['endDate'] = $assign['startDate'];
            }
        }
        $assign['active'] = true;
        $db['driverAssignments'][] = $assign;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'assignment' => $assign]);
        break;

    // ========================================
    // PARAMETRES (devise, seuils alertes)
    // ========================================
    case 'get_settings':
        $me = requireAuth('chauffeur');
        $settings = readJSON('settings.json');
        if (!$settings) {
            $settings = [
                'currency' => 'TND',
                'oilInterval' => 15000,
                'filterInterval' => 30000,
                'tireWearLimit' => 3,
                'insuranceAlertDays' => 30,
                'techInspectionAlertDays' => 30
            ];
            writeJSON('settings.json', $settings);
        }
        jsonResponse(['settings' => $settings]);
        break;

    case 'save_settings':
        $me = requireAuth('admin');
        $settings = $input['settings'];
        writeJSON('settings.json', $settings);
        jsonResponse(['success' => true]);
        break;

    // ========================================
    // PHOTOS (upload base64)
    // ========================================
    case 'add_photo':
        $me = requireAuth('chauffeur');
        $db = readJSON('fleet.json') ?: newDB();
        if (!isset($db['photos'])) $db['photos'] = [];
        $photo = [
            'id' => genId(),
            'truckRef' => $input['truckRef'],
            'type' => $input['type'], // avant, apres, panne
            'category' => isset($input['category']) ? $input['category'] : '', // oil, filter, fuel, tire, other
            'description' => isset($input['description']) ? $input['description'] : '',
            'data' => $input['data'], // base64 image
            'date' => date('Y-m-d'),
            'uploadedBy' => $me['username']
        ];
        $db['photos'][] = $photo;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'photoId' => $photo['id']]);
        break;

    case 'get_photos':
        $me = requireAuth('chauffeur');
        $db = readJSON('fleet.json') ?: newDB();
        $photos = isset($db['photos']) ? $db['photos'] : [];
        // Filter by truck if provided
        if (isset($_GET['truckRef'])) {
            $photos = array_values(array_filter($photos, function($p) {
                return $p['truckRef'] === $_GET['truckRef'];
            }));
        }
        // Filter by type if provided
        if (isset($_GET['type'])) {
            $photos = array_values(array_filter($photos, function($p) {
                return $p['type'] === $_GET['type'];
            }));
        }
        jsonResponse(['photos' => $photos]);
        break;

    case 'delete_photo':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        if (isset($db['photos'])) {
            $db['photos'] = array_values(array_filter($db['photos'], function($p) use ($input) {
                return $p['id'] !== $input['id'];
            }));
            writeJSON('fleet.json', $db);
        }
        jsonResponse(['success' => true]);
        break;

    // ========================================
    // BUDGET / TABLEAU DE BORD FINANCE
    // ========================================
    case 'add_budget':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        if (!isset($db['budgets'])) $db['budgets'] = [];
        $budget = [
            'id' => genId(),
            'truckRef' => $input['truckRef'],
            'month' => $input['month'], // YYYY-MM
            'fuelBudget' => isset($input['fuelBudget']) ? floatval($input['fuelBudget']) : 0,
            'maintenanceBudget' => isset($input['maintenanceBudget']) ? floatval($input['maintenanceBudget']) : 0,
            'tireBudget' => isset($input['tireBudget']) ? floatval($input['tireBudget']) : 0,
            'createdBy' => $me['username']
        ];
        $db['budgets'][] = $budget;
        writeJSON('fleet.json', $db);
        jsonResponse(['success' => true, 'budget' => $budget]);
        break;

    case 'get_budget_report':
        $me = requireAuth('comptable');
        $db = readJSON('fleet.json') ?: newDB();
        $month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
        $truckRef = isset($_GET['truckRef']) ? $_GET['truckRef'] : '';

        // Calculate actuals for the month
        $actualFuel = 0; $actualMaintenance = 0; $actualTires = 0;
        $monthPrefix = $month;

        foreach ($db['fuel'] as $f) {
            if (substr($f['date'], 0, 7) === $monthPrefix && (!$truckRef || $f['truckRef'] === $truckRef)) {
                $actualFuel += ($f['liters'] ?? 0) * ($f['pricePerL'] ?? 0);
            }
        }
        foreach ($db['oilChanges'] as $o) {
            if (substr($o['date'], 0, 7) === $monthPrefix && (!$truckRef || $o['truckRef'] === $truckRef)) {
                $actualMaintenance += ($o['liters'] ?? 0) * ($o['pricePerL'] ?? 0);
            }
        }
        foreach ($db['filterChanges'] as $f) {
            if (substr($f['date'], 0, 7) === $monthPrefix && (!$truckRef || $f['truckRef'] === $truckRef)) {
                $actualMaintenance += ($f['qty'] ?? 0) * ($f['price'] ?? 0);
            }
        }
        if (isset($db['tires'])) {
            foreach ($db['tires'] as $t) {
                if (substr($t['installDate'] ?? '', 0, 7) === $monthPrefix && (!$truckRef || $t['truckRef'] === $truckRef)) {
                    $actualTires += $t['price'] ?? 0;
                }
            }
        }

        // Find budget
        $budget = null;
        if (isset($db['budgets'])) {
            foreach ($db['budgets'] as $b) {
                if ($b['month'] === $month && (!$truckRef || $b['truckRef'] === $truckRef)) {
                    $budget = $b;
                    break;
                }
            }
        }

        jsonResponse([
            'success' => true,
            'month' => $month,
            'truckRef' => $truckRef ?: 'ALL',
            'budget' => $budget,
            'actual' => [
                'fuel' => $actualFuel,
                'maintenance' => $actualMaintenance,
                'tires' => $actualTires,
                'total' => $actualFuel + $actualMaintenance + $actualTires
            ]
        ]);
        break;

    // ========================================
    // ALERTES WHATSAPP
    // ========================================
    case 'get_alerts_whatsapp':
        $me = requireAuth('manager');
        $db = readJSON('fleet.json') ?: newDB();
        $settings = readJSON('settings.json') ?: [];
        $alerts = [];
        $today = date('Y-m-d');

        // Oil alerts
        $oilInterval = $settings['oilInterval'] ?? 15000;
        foreach ($db['trucks'] as $t) {
            $km = $t['km'] ?? 0;
            $lastOil = null;
            if (isset($db['oilChanges'])) {
                foreach ($db['oilChanges'] as $o) {
                    if ($o['truckRef'] === $t['ref']) {
                        if (!$lastOil || $o['date'] > $lastOil['date']) $lastOil = $o;
                    }
                }
            }
            $kmSince = $lastOil ? ($km - ($lastOil['km'] ?? 0)) : $km;
            if ($kmSince >= $oilInterval) {
                $alerts[] = [
                    'type' => 'Vidange',
                    'truckRef' => $t['ref'],
                    'message' => 'Vidange requise pour ' . $t['ref'] . ' (' . $kmSince . ' km depuis la derniere)',
                    'priority' => 'HIGH'
                ];
            }
        }

        // Document expiry alerts
        $alertDays = $settings['insuranceAlertDays'] ?? 30;
        if (isset($db['documents'])) {
            foreach ($db['documents'] as $doc) {
                if (!isset($doc['expiryDate'])) continue;
                $diff = (strtotime($doc['expiryDate']) - time()) / 86400;
                if ($diff <= $alertDays) {
                    $alerts[] = [
                        'type' => $doc['type'],
                        'truckRef' => $doc['truckRef'],
                        'message' => $doc['type'] . ' ' . ($doc['truckRef'] ?? '') . ' expire dans ' . round($diff) . ' jours (' . $doc['expiryDate'] . ')',
                        'priority' => $diff < 0 ? 'CRITICAL' : 'HIGH'
                    ];
                }
            }
        }

        // Tire alerts
        if (isset($db['tires'])) {
            foreach ($db['tires'] as $tire) {
                if ($tire['status'] === 'A remplacer') {
                    $alerts[] = [
                        'type' => 'Pneu',
                        'truckRef' => $tire['truckRef'] ?? '',
                        'message' => 'Pneu ' . ($tire['position'] ?? '') . ' ' . ($tire['brand'] ?? '') . ' a remplacer sur ' . ($tire['truckRef'] ?? ''),
                        'priority' => 'MEDIUM'
                    ];
                }
            }
        }

        jsonResponse(['alerts' => $alerts, 'count' => count($alerts)]);
        break;

    case 'save_whatsapp_config':
        $me = requireAuth('admin');
        $settings = readJSON('settings.json') ?: [];
        $settings['whatsappPhone'] = $input['phone'];
        $settings['whatsappEnabled'] = $input['enabled'] ? true : false;
        writeJSON('settings.json', $settings);
        jsonResponse(['success' => true]);
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
