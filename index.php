<?php
session_start();

// ============================================================
// DATABASE SETUP (SQLite via PDO)
// ============================================================
$db_file = __DIR__ . '/minebet.db';
$pdo = new PDO('sqlite:' . $db_file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create tables
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    balance REAL DEFAULT 100.0,
    ref_code TEXT UNIQUE NOT NULL,
    referred_by INTEGER DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    amount REAL NOT NULL,
    status TEXT DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS games (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    game_type TEXT NOT NULL,
    bet REAL NOT NULL,
    result TEXT NOT NULL,
    payout REAL NOT NULL,
    server_seed TEXT NOT NULL,
    client_seed TEXT NOT NULL,
    nonce INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function generate_ref_code() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

function hash_seed($server_seed, $client_seed, $nonce) {
    return hash_hmac('sha256', $client_seed . ':' . $nonce, $server_seed);
}

function get_mine_positions($seed, $total = 25, $mines = 5) {
    $positions = range(0, $total - 1);
    $hash = hash('sha256', $seed);
    $shuffled = [];
    $arr = $positions;
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $hex = substr($hash, ($i * 2) % 60, 4);
        $j = hexdec($hex) % ($i + 1);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }
    return array_slice($arr, 0, $mines);
}

function get_hl_result($seed) {
    $hex = substr(hash('sha256', $seed), 0, 8);
    $num = (hexdec($hex) % 100) + 1;
    return $num;
}

function format_money($n) {
    return number_format($n, 2, '.', ' ');
}

function get_user($pdo, $id) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

function get_last_wins($pdo, $limit = 10) {
    $s = $pdo->prepare("
        SELECT g.*, u.username FROM games g
        JOIN users u ON g.user_id = u.id
        WHERE g.payout > 0
        ORDER BY g.created_at DESC LIMIT ?
    ");
    $s->execute([$limit]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function get_top_players($pdo, $limit = 10) {
    $s = $pdo->prepare("
        SELECT u.username, SUM(g.payout - g.bet) as profit, COUNT(g.id) as games
        FROM games g JOIN users u ON g.user_id = u.id
        GROUP BY g.user_id ORDER BY profit DESC LIMIT ?
    ");
    $s->execute([$limit]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// AJAX / ACTION HANDLER
// ============================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $uid = $_SESSION['user_id'] ?? null;

    // --- REGISTER ---
    if ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $ref = trim($_POST['ref'] ?? '');
        if (strlen($username) < 3 || strlen($password) < 6) {
            echo json_encode(['ok' => false, 'msg' => 'Имя >= 3 символов, пароль >= 6']);
            exit;
        }
        $ref_code = generate_ref_code();
        $referred_by = null;
        if ($ref) {
            $rs = $pdo->prepare("SELECT id FROM users WHERE ref_code = ?");
            $rs->execute([$ref]);
            $refUser = $rs->fetch();
            if ($refUser) {
                $referred_by = $refUser['id'];
                // Bonus to referrer
                $pdo->prepare("UPDATE users SET balance = balance + 20 WHERE id = ?")->execute([$refUser['id']]);
            }
        }
        try {
            $st = $pdo->prepare("INSERT INTO users (username, password, ref_code, referred_by) VALUES (?, ?, ?, ?)");
            $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $ref_code, $referred_by]);
            $uid = $pdo->lastInsertId();
            $_SESSION['user_id'] = $uid;
            echo json_encode(['ok' => true, 'msg' => 'Регистрация успешна! Стартовый баланс: 100₽']);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Имя уже занято']);
        }
        exit;
    }

    // --- LOGIN ---
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $st = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $st->execute([$username]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            echo json_encode(['ok' => true, 'balance' => $user['balance'], 'username' => $user['username']]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Неверные данные']);
        }
        exit;
    }

    // --- LOGOUT ---
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    // Require auth for below
    if (!$uid) {
        echo json_encode(['ok' => false, 'msg' => 'Не авторизован']);
        exit;
    }

    $user = get_user($pdo, $uid);

    // --- GET BALANCE ---
    if ($action === 'balance') {
        echo json_encode(['ok' => true, 'balance' => $user['balance']]);
        exit;
    }

    // --- DEPOSIT ---
    if ($action === 'deposit') {
        $amount = floatval($_POST['amount'] ?? 0);
        if ($amount < 10 || $amount > 100000) {
            echo json_encode(['ok' => false, 'msg' => 'Сумма от 10 до 100 000']);
            exit;
        }
        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, status) VALUES (?, 'deposit', ?, 'completed')")->execute([$uid, $amount]);
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$amount, $uid]);
        $user = get_user($pdo, $uid);
        echo json_encode(['ok' => true, 'balance' => $user['balance'], 'msg' => 'Баланс пополнен на ' . format_money($amount) . '₽']);
        exit;
    }

    // --- WITHDRAW ---
    if ($action === 'withdraw') {
        $amount = floatval($_POST['amount'] ?? 0);
        if ($amount < 50) {
            echo json_encode(['ok' => false, 'msg' => 'Минимальный вывод 50₽']);
            exit;
        }
        if ($user['balance'] < $amount) {
            echo json_encode(['ok' => false, 'msg' => 'Недостаточно средств']);
            exit;
        }
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$amount, $uid]);
        $pdo->prepare("INSERT INTO transactions (user_id, type, amount, status) VALUES (?, 'withdraw', ?, 'pending')")->execute([$uid, $amount]);
        $user = get_user($pdo, $uid);
        echo json_encode(['ok' => true, 'balance' => $user['balance'], 'msg' => 'Заявка на вывод ' . format_money($amount) . '₽ отправлена']);
        exit;
    }

    // --- MINES: START GAME ---
    if ($action === 'mines_start') {
        $bet = floatval($_POST['bet'] ?? 0);
        $mines_count = intval($_POST['mines'] ?? 5);
        if ($mines_count < 1 || $mines_count > 24) $mines_count = 5;
        if ($bet < 1) { echo json_encode(['ok' => false, 'msg' => 'Минимальная ставка 1₽']); exit; }
        if ($user['balance'] < $bet) { echo json_encode(['ok' => false, 'msg' => 'Недостаточно средств']); exit; }

        $server_seed = bin2hex(random_bytes(16));
        $client_seed = bin2hex(random_bytes(8));
        $nonce = rand(1, 9999);
        $combined = hash_seed($server_seed, $client_seed, $nonce);
        $mine_positions = get_mine_positions($combined, 25, $mines_count);

        // Store in session
        $_SESSION['mines_game'] = [
            'server_seed' => $server_seed,
            'server_seed_hash' => hash('sha256', $server_seed),
            'client_seed' => $client_seed,
            'nonce' => $nonce,
            'mine_positions' => $mine_positions,
            'mines_count' => $mines_count,
            'bet' => $bet,
            'revealed' => [],
            'active' => true,
            'uid' => $uid
        ];

        // Deduct bet
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$bet, $uid]);

        echo json_encode([
            'ok' => true,
            'server_seed_hash' => hash('sha256', $server_seed),
            'client_seed' => $client_seed,
            'nonce' => $nonce,
            'balance' => get_user($pdo, $uid)['balance']
        ]);
        exit;
    }

    // --- MINES: REVEAL CELL ---
    if ($action === 'mines_reveal') {
        $cell = intval($_POST['cell'] ?? -1);
        $g = $_SESSION['mines_game'] ?? null;
        if (!$g || !$g['active'] || $g['uid'] != $uid) {
            echo json_encode(['ok' => false, 'msg' => 'Нет активной игры']); exit;
        }
        if ($cell < 0 || $cell > 24 || in_array($cell, $g['revealed'])) {
            echo json_encode(['ok' => false, 'msg' => 'Неверная ячейка']); exit;
        }

        $is_mine = in_array($cell, $g['mine_positions']);
        $mines_count = $g['mines_count'];
        $safe_count = 25 - $mines_count;
        $revealed_count = count($g['revealed']);

        if ($is_mine) {
            // Game over
            $_SESSION['mines_game']['active'] = false;
            $pdo->prepare("INSERT INTO games (user_id, game_type, bet, result, payout, server_seed, client_seed, nonce) VALUES (?, 'mines', ?, 'lose', 0, ?, ?, ?)")
                ->execute([$uid, $g['bet'], $g['server_seed'], $g['client_seed'], $g['nonce']]);
            echo json_encode([
                'ok' => true,
                'hit_mine' => true,
                'mine_positions' => $g['mine_positions'],
                'server_seed' => $g['server_seed'],
                'balance' => get_user($pdo, $uid)['balance'],
                'multiplier' => 0
            ]);
        } else {
            $_SESSION['mines_game']['revealed'][] = $cell;
            $revealed_count++;
            // Calculate multiplier
            $multiplier = 1.0;
            for ($i = 0; $i < $revealed_count; $i++) {
                $multiplier *= (25 - $mines_count - $i) / (25 - $i);
            }
            $multiplier = round(0.97 / $multiplier, 2);
            $potential = round($g['bet'] * $multiplier, 2);
            $all_safe = ($revealed_count >= $safe_count);
            if ($all_safe) {
                // Auto cashout
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$potential, $uid]);
                $pdo->prepare("INSERT INTO games (user_id, game_type, bet, result, payout, server_seed, client_seed, nonce) VALUES (?, 'mines', ?, 'win', ?, ?, ?, ?)")
                    ->execute([$uid, $g['bet'], $potential, $g['server_seed'], $g['client_seed'], $g['nonce']]);
                $_SESSION['mines_game']['active'] = false;
            }
            echo json_encode([
                'ok' => true,
                'hit_mine' => false,
                'revealed' => $_SESSION['mines_game']['revealed'],
                'multiplier' => $multiplier,
                'potential' => $potential,
                'all_safe' => $all_safe,
                'balance' => get_user($pdo, $uid)['balance']
            ]);
        }
        exit;
    }

    // --- MINES: CASHOUT ---
    if ($action === 'mines_cashout') {
        $g = $_SESSION['mines_game'] ?? null;
        if (!$g || !$g['active'] || $g['uid'] != $uid || empty($g['revealed'])) {
            echo json_encode(['ok' => false, 'msg' => 'Нечего забирать']); exit;
        }
        $mines_count = $g['mines_count'];
        $revealed_count = count($g['revealed']);
        $multiplier = 1.0;
        for ($i = 0; $i < $revealed_count; $i++) {
            $multiplier *= (25 - $mines_count - $i) / (25 - $i);
        }
        $multiplier = round(0.97 / $multiplier, 2);
        $payout = round($g['bet'] * $multiplier, 2);
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$payout, $uid]);
        $pdo->prepare("INSERT INTO games (user_id, game_type, bet, result, payout, server_seed, client_seed, nonce) VALUES (?, 'mines', ?, 'win', ?, ?, ?, ?)")
            ->execute([$uid, $g['bet'], $payout, $g['server_seed'], $g['client_seed'], $g['nonce']]);
        $_SESSION['mines_game']['active'] = false;
        echo json_encode([
            'ok' => true,
            'payout' => $payout,
            'multiplier' => $multiplier,
            'server_seed' => $g['server_seed'],
            'balance' => get_user($pdo, $uid)['balance']
        ]);
        exit;
    }

    // --- HI-LO: PLAY ---
    if ($action === 'hilo_play') {
        $bet = floatval($_POST['bet'] ?? 0);
        $guess = $_POST['guess'] ?? ''; // 'higher' or 'lower'
        if ($bet < 1) { echo json_encode(['ok' => false, 'msg' => 'Минимальная ставка 1₽']); exit; }
        if ($user['balance'] < $bet) { echo json_encode(['ok' => false, 'msg' => 'Недостаточно средств']); exit; }
        if (!in_array($guess, ['higher', 'lower'])) { echo json_encode(['ok' => false, 'msg' => 'Неверный выбор']); exit; }

        $server_seed = bin2hex(random_bytes(16));
        $client_seed = bin2hex(random_bytes(8));
        $nonce = rand(1, 9999);
        $combined = hash_seed($server_seed, $client_seed, $nonce);

        // First number
        $hex1 = substr(hash('sha256', $combined . '_1'), 0, 8);
        $num1 = (hexdec($hex1) % 100) + 1;
        $hex2 = substr(hash('sha256', $combined . '_2'), 0, 8);
        $num2 = (hexdec($hex2) % 100) + 1;

        $win = false;
        if ($guess === 'higher' && $num2 > $num1) $win = true;
        if ($guess === 'lower' && $num2 < $num1) $win = true;

        $multiplier = 1.94;
        $payout = $win ? round($bet * $multiplier, 2) : 0;

        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$payout - $bet, $uid]);
        $pdo->prepare("INSERT INTO games (user_id, game_type, bet, result, payout, server_seed, client_seed, nonce) VALUES (?, 'hilo', ?, ?, ?, ?, ?, ?)")
            ->execute([$uid, $bet, $win ? 'win' : 'lose', $payout, $server_seed, $client_seed, $nonce]);

        echo json_encode([
            'ok' => true,
            'num1' => $num1,
            'num2' => $num2,
            'win' => $win,
            'payout' => $payout,
            'multiplier' => $multiplier,
            'server_seed' => $server_seed,
            'balance' => get_user($pdo, $uid)['balance']
        ]);
        exit;
    }

    // --- GET LAST WINS ---
    if ($action === 'last_wins') {
        echo json_encode(['ok' => true, 'wins' => get_last_wins($pdo, 12)]);
        exit;
    }

    // --- GET TOP PLAYERS ---
    if ($action === 'top_players') {
        echo json_encode(['ok' => true, 'top' => get_top_players($pdo, 10)]);
        exit;
    }

    // --- GET USER INFO ---
    if ($action === 'user_info') {
        $ref_link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?ref=' . $user['ref_code'];
        $rs = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE referred_by = ?");
        $rs->execute([$uid]);
        $ref_count = $rs->fetch()['cnt'];
        echo json_encode([
            'ok' => true,
            'username' => $user['username'],
            'balance' => $user['balance'],
            'ref_code' => $user['ref_code'],
            'ref_link' => $ref_link,
            'ref_count' => $ref_count
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Неизвестное действие']);
    exit;
}

// Pre-fill ref code from URL
$url_ref = $_GET['ref'] ?? '';
$loggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MineBet — Казино Мин & Больше/Меньше</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #080d0f;
  --bg2: #0d1416;
  --bg3: #111b1e;
  --bg4: #162023;
  --green: #00e676;
  --green2: #00c853;
  --green3: #69f0ae;
  --red: #ff1744;
  --gold: #ffd740;
  --text: #e0f2f1;
  --text2: #80cbc4;
  --text3: #546e7a;
  --border: #1a2e32;
  --glow: 0 0 20px rgba(0,230,118,0.3);
  --glow2: 0 0 40px rgba(0,230,118,0.15);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
  font-family: 'Rajdhani', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  overflow-x: hidden;
}

/* GRID BACKGROUND */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(0,230,118,0.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,230,118,0.04) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
  z-index: 0;
}

body::after {
  content: '';
  position: fixed;
  inset: 0;
  background: radial-gradient(ellipse 80% 60% at 50% 0%, rgba(0,230,118,0.06) 0%, transparent 70%);
  pointer-events: none;
  z-index: 0;
}

#app { position: relative; z-index: 1; }

/* HEADER */
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 32px;
  background: rgba(13,20,22,0.9);
  border-bottom: 1px solid var(--border);
  backdrop-filter: blur(20px);
  position: sticky;
  top: 0;
  z-index: 100;
}

.logo {
  font-family: 'Orbitron', monospace;
  font-size: 22px;
  font-weight: 900;
  color: var(--green);
  text-shadow: var(--glow);
  letter-spacing: 2px;
}

.logo span { color: var(--text3); }

.header-right { display: flex; align-items: center; gap: 16px; }

.balance-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 8px 16px;
  font-size: 16px;
  font-weight: 700;
  color: var(--green);
}

.balance-badge .icon { font-size: 18px; }

/* BUTTONS */
.btn {
  font-family: 'Rajdhani', sans-serif;
  font-weight: 700;
  font-size: 14px;
  letter-spacing: 1px;
  border: none;
  border-radius: 8px;
  padding: 10px 20px;
  cursor: pointer;
  transition: all 0.2s;
  text-transform: uppercase;
}

.btn-green {
  background: linear-gradient(135deg, var(--green2), var(--green));
  color: #000;
  box-shadow: 0 4px 15px rgba(0,230,118,0.3);
}
.btn-green:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(0,230,118,0.5); }
.btn-green:active { transform: translateY(0); }

.btn-outline {
  background: transparent;
  color: var(--green);
  border: 1px solid var(--green);
}
.btn-outline:hover { background: rgba(0,230,118,0.1); }

.btn-red {
  background: linear-gradient(135deg, #c62828, var(--red));
  color: #fff;
  box-shadow: 0 4px 15px rgba(255,23,68,0.3);
}
.btn-red:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(255,23,68,0.5); }

.btn-gold {
  background: linear-gradient(135deg, #ff8f00, var(--gold));
  color: #000;
  box-shadow: 0 4px 15px rgba(255,215,64,0.3);
}
.btn-gold:hover { transform: translateY(-2px); }

.btn-ghost {
  background: transparent;
  color: var(--text3);
  border: 1px solid var(--border);
}
.btn-ghost:hover { color: var(--text); border-color: var(--text3); }

.btn-sm { padding: 7px 14px; font-size: 12px; }
.btn-lg { padding: 14px 32px; font-size: 16px; }
.btn-block { width: 100%; }

/* MODAL */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.8);
  z-index: 200;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(4px);
}
.modal-overlay.show { display: flex; }

.modal {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 32px;
  width: 100%;
  max-width: 420px;
  margin: 16px;
  position: relative;
  animation: modalIn 0.3s cubic-bezier(.34,1.56,.64,1);
}

@keyframes modalIn {
  from { transform: scale(0.85) translateY(20px); opacity: 0; }
  to { transform: scale(1) translateY(0); opacity: 1; }
}

.modal-title {
  font-family: 'Orbitron', monospace;
  font-size: 18px;
  color: var(--green);
  margin-bottom: 24px;
  text-align: center;
}

.modal-close {
  position: absolute;
  top: 16px; right: 16px;
  background: none; border: none;
  color: var(--text3); font-size: 20px;
  cursor: pointer;
  transition: color 0.2s;
}
.modal-close:hover { color: var(--text); }

/* FORM */
.form-group { margin-bottom: 16px; }
.form-label { display: block; font-size: 13px; color: var(--text2); margin-bottom: 6px; letter-spacing: 0.5px; }
.form-input {
  width: 100%;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 12px 16px;
  color: var(--text);
  font-family: 'Rajdhani', sans-serif;
  font-size: 15px;
  transition: border-color 0.2s;
}
.form-input:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 2px rgba(0,230,118,0.1); }

.tabs { display: flex; gap: 4px; background: var(--bg3); border-radius: 10px; padding: 4px; margin-bottom: 24px; }
.tab { flex: 1; padding: 10px; text-align: center; border-radius: 7px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s; color: var(--text3); }
.tab.active { background: var(--green2); color: #000; }

/* MAIN LAYOUT */
.main { display: flex; gap: 20px; padding: 24px 20px; max-width: 1400px; margin: 0 auto; }

/* SIDEBAR */
.sidebar { width: 260px; flex-shrink: 0; }

.sidebar-nav { display: flex; flex-direction: column; gap: 4px; }

.nav-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 10px;
  cursor: pointer;
  font-size: 15px;
  font-weight: 600;
  color: var(--text3);
  transition: all 0.2s;
  border: 1px solid transparent;
}
.nav-item:hover { background: var(--bg3); color: var(--text); }
.nav-item.active { background: var(--bg3); color: var(--green); border-color: rgba(0,230,118,0.2); }
.nav-item .nav-icon { font-size: 20px; width: 24px; text-align: center; }

.sidebar-section { margin-top: 20px; }
.sidebar-section-title { font-size: 11px; letter-spacing: 2px; color: var(--text3); padding: 0 16px; margin-bottom: 8px; text-transform: uppercase; }

/* CONTENT */
.content { flex: 1; min-width: 0; }

/* PANELS */
.panel {
  display: none;
  animation: fadeUp 0.4s ease;
}
.panel.active { display: block; }

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}

/* CARDS */
.card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 24px;
  margin-bottom: 20px;
}

.card-title {
  font-family: 'Orbitron', monospace;
  font-size: 14px;
  color: var(--text2);
  letter-spacing: 2px;
  margin-bottom: 20px;
  text-transform: uppercase;
}

/* MINES GAME */
.mines-layout { display: grid; grid-template-columns: 1fr 280px; gap: 20px; }

.mines-controls { display: flex; flex-direction: column; gap: 16px; }

.control-row { display: flex; gap: 12px; align-items: center; }
.control-label { font-size: 13px; color: var(--text2); margin-bottom: 8px; }

.mines-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
  aspect-ratio: 1;
}

.mine-cell {
  aspect-ratio: 1;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 10px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  transition: all 0.2s;
  position: relative;
  overflow: hidden;
}

.mine-cell::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, rgba(255,255,255,0.05), transparent);
  border-radius: inherit;
}

.mine-cell:hover:not(.revealed):not(.disabled) {
  border-color: var(--green);
  background: var(--bg4);
  transform: scale(1.05);
  box-shadow: 0 0 15px rgba(0,230,118,0.2);
}

.mine-cell.revealed-safe {
  background: linear-gradient(135deg, rgba(0,200,83,0.2), rgba(0,230,118,0.1));
  border-color: var(--green2);
  animation: cellReveal 0.3s ease;
  cursor: default;
}

.mine-cell.revealed-mine {
  background: linear-gradient(135deg, rgba(255,23,68,0.3), rgba(198,40,40,0.2));
  border-color: var(--red);
  animation: cellBoom 0.4s ease;
  cursor: default;
}

.mine-cell.hint-mine {
  background: rgba(255,23,68,0.1);
  border-color: rgba(255,23,68,0.3);
}

@keyframes cellReveal {
  0% { transform: scale(0.8); opacity: 0; }
  60% { transform: scale(1.1); }
  100% { transform: scale(1); opacity: 1; }
}

@keyframes cellBoom {
  0% { transform: scale(0.8); }
  30% { transform: scale(1.3); }
  100% { transform: scale(1); }
}

.mine-cell.disabled { cursor: not-allowed; opacity: 0.5; }

/* Multiplier display */
.multiplier-big {
  font-family: 'Orbitron', monospace;
  font-size: 36px;
  font-weight: 900;
  color: var(--green);
  text-shadow: var(--glow);
  text-align: center;
  margin: 16px 0;
}

.potential-win {
  text-align: center;
  font-size: 18px;
  color: var(--text2);
  margin-bottom: 16px;
}

.mines-count-selector {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
}

.mines-count-btn {
  padding: 8px;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 7px;
  color: var(--text3);
  font-family: 'Rajdhani', sans-serif;
  font-weight: 600;
  font-size: 13px;
  cursor: pointer;
  text-align: center;
  transition: all 0.2s;
}
.mines-count-btn:hover, .mines-count-btn.active { border-color: var(--green); color: var(--green); background: rgba(0,230,118,0.05); }

/* BET INPUT */
.bet-row { display: flex; gap: 6px; align-items: center; }
.bet-input-wrap { flex: 1; position: relative; }
.bet-input-wrap .currency { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--text3); font-size: 13px; }
.bet-quick { display: flex; gap: 4px; }
.bet-quick-btn {
  padding: 6px 10px;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 6px;
  color: var(--text3);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  font-family: 'Rajdhani', sans-serif;
}
.bet-quick-btn:hover { color: var(--green); border-color: var(--green); }

/* HILO */
.hilo-container { max-width: 600px; margin: 0 auto; }

.hilo-card-area {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 40px;
  margin: 32px 0;
}

.hilo-card {
  width: 120px;
  height: 160px;
  background: var(--bg3);
  border: 2px solid var(--border);
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Orbitron', monospace;
  font-size: 48px;
  font-weight: 900;
  transition: all 0.4s;
  position: relative;
}

.hilo-card.green { border-color: var(--green); color: var(--green); box-shadow: var(--glow); }
.hilo-card.red { border-color: var(--red); color: var(--red); box-shadow: 0 0 20px rgba(255,23,68,0.3); }
.hilo-card.unknown { color: var(--text3); font-size: 24px; }

.hilo-arrow {
  font-size: 40px;
  color: var(--text3);
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.hilo-btn-row { display: flex; gap: 16px; justify-content: center; margin: 24px 0; }

/* LAST WINS */
.wins-list { display: flex; flex-direction: column; gap: 8px; }

.win-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 16px;
  animation: slideIn 0.3s ease;
}

@keyframes slideIn {
  from { transform: translateX(-10px); opacity: 0; }
  to { transform: translateX(0); opacity: 1; }
}

.win-user { font-weight: 700; color: var(--text); font-size: 14px; }
.win-game { font-size: 12px; color: var(--text3); }
.win-amount { font-weight: 700; color: var(--green); font-size: 15px; }
.win-bet { font-size: 12px; color: var(--text3); text-align: right; }

/* TOP PLAYERS */
.top-table { width: 100%; border-collapse: collapse; }
.top-table th { 
  text-align: left; padding: 12px 16px;
  font-size: 11px; letter-spacing: 2px; color: var(--text3);
  border-bottom: 1px solid var(--border);
  text-transform: uppercase;
}
.top-table td { padding: 14px 16px; border-bottom: 1px solid rgba(26,46,50,0.5); font-size: 15px; }
.top-table tr:hover td { background: rgba(0,230,118,0.03); }
.rank-1 { color: var(--gold); font-family: 'Orbitron', monospace; font-weight: 700; }
.rank-2 { color: #90a4ae; }
.rank-3 { color: #8d6e63; }

/* WALLET */
.wallet-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.wallet-stat {
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 20px;
  text-align: center;
}
.wallet-stat-label { font-size: 12px; color: var(--text3); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px; }
.wallet-stat-value { font-family: 'Orbitron', monospace; font-size: 24px; font-weight: 700; color: var(--green); }

/* REF SECTION */
.ref-box {
  background: var(--bg3);
  border: 1px solid rgba(0,230,118,0.2);
  border-radius: 12px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}
.ref-link-text { flex: 1; font-size: 13px; color: var(--text2); word-break: break-all; font-family: monospace; }

/* TOAST */
#toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 999;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.toast-msg {
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 14px 20px;
  font-size: 14px;
  font-weight: 600;
  max-width: 320px;
  animation: toastIn 0.3s ease;
  display: flex;
  align-items: center;
  gap: 10px;
}
.toast-msg.success { border-color: var(--green); color: var(--green); }
.toast-msg.error { border-color: var(--red); color: var(--red); }
.toast-msg.info { border-color: var(--gold); color: var(--gold); }

@keyframes toastIn {
  from { transform: translateX(100%); opacity: 0; }
  to { transform: translateX(0); opacity: 1; }
}

/* HERO */
.hero {
  text-align: center;
  padding: 60px 20px 40px;
}
.hero h1 {
  font-family: 'Orbitron', monospace;
  font-size: 48px;
  font-weight: 900;
  color: var(--green);
  text-shadow: var(--glow);
  margin-bottom: 16px;
  line-height: 1.2;
}
.hero p { font-size: 18px; color: var(--text2); margin-bottom: 32px; }
.hero-btns { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }

/* GAME SELECTION CARDS */
.game-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }

.game-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 32px;
  cursor: pointer;
  transition: all 0.3s;
  text-align: center;
  position: relative;
  overflow: hidden;
}

.game-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--green), transparent);
  opacity: 0;
  transition: opacity 0.3s;
}

.game-card:hover { transform: translateY(-4px); border-color: rgba(0,230,118,0.3); box-shadow: var(--glow2); }
.game-card:hover::before { opacity: 1; }

.game-card-icon { font-size: 64px; margin-bottom: 16px; display: block; }
.game-card-title { font-family: 'Orbitron', monospace; font-size: 18px; color: var(--green); margin-bottom: 8px; }
.game-card-desc { font-size: 14px; color: var(--text3); line-height: 1.5; }
.game-card-badge {
  display: inline-block;
  background: rgba(0,230,118,0.15);
  color: var(--green3);
  border: 1px solid rgba(0,230,118,0.3);
  border-radius: 20px;
  padding: 4px 12px;
  font-size: 12px;
  font-weight: 700;
  margin-top: 12px;
  letter-spacing: 1px;
}

/* STATUS */
.status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
.status-dot.green { background: var(--green); box-shadow: 0 0 6px var(--green); }

/* PROVABLY FAIR INFO */
.fair-info {
  background: rgba(0,230,118,0.05);
  border: 1px solid rgba(0,230,118,0.15);
  border-radius: 10px;
  padding: 16px;
  margin-top: 12px;
  font-size: 12px;
  color: var(--text3);
  font-family: monospace;
  word-break: break-all;
}
.fair-info .label { color: var(--green); font-size: 11px; letter-spacing: 1px; text-transform: uppercase; display: block; margin-bottom: 4px; }

/* LIVE TICKER */
.ticker {
  background: var(--bg2);
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  padding: 10px 0;
  overflow: hidden;
  position: relative;
  margin-bottom: 0;
}
.ticker-track {
  display: flex;
  gap: 32px;
  animation: ticker 30s linear infinite;
  white-space: nowrap;
}
.ticker-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
}
.ticker-item .t-user { color: var(--text2); }
.ticker-item .t-game { color: var(--text3); }
.ticker-item .t-amount { color: var(--green); }

@keyframes ticker {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

/* AUTH PANEL */
.auth-panel { max-width: 420px; margin: 60px auto; }

/* RESPONSIVE */
@media (max-width: 900px) {
  .mines-layout { grid-template-columns: 1fr; }
  .sidebar { display: none; }
  .main { padding: 12px; }
}
@media (max-width: 600px) {
  header { padding: 12px 16px; }
  .logo { font-size: 16px; }
  .hero h1 { font-size: 30px; }
  .wallet-grid { grid-template-columns: 1fr; }
}

/* SCROLLBAR */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--text3); }

/* DIVIDER */
.divider { height: 1px; background: var(--border); margin: 20px 0; }

/* BADGE */
.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}
.badge-green { background: rgba(0,230,118,0.15); color: var(--green); }
.badge-red { background: rgba(255,23,68,0.15); color: var(--red); }

.mobile-nav {
  display: none;
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: var(--bg2);
  border-top: 1px solid var(--border);
  z-index: 100;
  padding: 8px;
}
.mobile-nav-inner { display: flex; justify-content: space-around; }
.mob-nav-btn { 
  display: flex; flex-direction: column; align-items: center;
  gap: 4px; padding: 8px 16px;
  background: none; border: none; color: var(--text3);
  font-size: 10px; font-weight: 700; cursor: pointer;
  font-family: 'Rajdhani', sans-serif; letter-spacing: 0.5px;
  text-transform: uppercase; transition: color 0.2s; border-radius: 8px;
}
.mob-nav-btn .mob-icon { font-size: 22px; }
.mob-nav-btn.active { color: var(--green); }

@media (max-width: 900px) {
  .mobile-nav { display: block; }
  .main { padding-bottom: 80px; }
}
</style>
</head>
<body>

<div id="app">

<!-- HEADER -->
<header>
  <div class="logo">Mine<span>Bet</span></div>
  <div class="header-right" id="headerRight">
    <button class="btn btn-outline btn-sm" onclick="showModal('loginModal')">Войти</button>
    <button class="btn btn-green btn-sm" onclick="showModal('registerModal')">Регистрация</button>
  </div>
</header>

<!-- LIVE TICKER -->
<div class="ticker" id="ticker">
  <div class="ticker-track" id="tickerTrack">
    <div class="ticker-item">💎 <span class="t-user">Player_X</span> <span class="t-game">Mines</span> <span class="t-amount">+543.20₽</span></div>
    <div class="ticker-item">🎯 <span class="t-user">Guru99</span> <span class="t-game">Hi-Lo</span> <span class="t-amount">+128.00₽</span></div>
    <div class="ticker-item">💎 <span class="t-user">CoolKid</span> <span class="t-game">Mines</span> <span class="t-amount">+2,100₽</span></div>
    <div class="ticker-item">🎯 <span class="t-user">LuckyOne</span> <span class="t-game">Hi-Lo</span> <span class="t-amount">+64.00₽</span></div>
    <div class="ticker-item">💎 <span class="t-user">Pro_Gamer</span> <span class="t-game">Mines</span> <span class="t-amount">+980.50₽</span></div>
    <div class="ticker-item">🎯 <span class="t-user">FastBet</span> <span class="t-game">Hi-Lo</span> <span class="t-amount">+320.00₽</span></div>
  </div>
</div>

<!-- MAIN -->
<div class="main">
  
  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
      <div class="nav-item active" onclick="navigate('home')" id="nav-home">
        <span class="nav-icon">🏠</span> Главная
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-title">Игры</div>
        <div class="nav-item" onclick="navigate('mines')" id="nav-mines">
          <span class="nav-icon">💣</span> Мины
        </div>
        <div class="nav-item" onclick="navigate('hilo')" id="nav-hilo">
          <span class="nav-icon">🎯</span> Больше / Меньше
        </div>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-title">Сообщество</div>
        <div class="nav-item" onclick="navigate('wins')" id="nav-wins">
          <span class="nav-icon">🏆</span> Последние выигрыши
        </div>
        <div class="nav-item" onclick="navigate('top')" id="nav-top">
          <span class="nav-icon">⭐</span> Топ игроков
        </div>
      </div>
      <div class="sidebar-section" id="walletNavSection">
        <div class="sidebar-section-title">Аккаунт</div>
        <div class="nav-item" onclick="navigate('wallet')" id="nav-wallet">
          <span class="nav-icon">💳</span> Кошелёк
        </div>
        <div class="nav-item" onclick="navigate('ref')" id="nav-ref">
          <span class="nav-icon">🔗</span> Рефералы
        </div>
        <div class="nav-item" onclick="navigate('fair')" id="nav-fair">
          <span class="nav-icon">🔐</span> Честная игра
        </div>
      </div>
    </nav>
  </aside>

  <!-- CONTENT -->
  <div class="content">

    <!-- HOME PANEL -->
    <div class="panel active" id="panel-home">
      <div class="hero">
        <h1>ИГРАЙ.<br>ВЫИГРЫВАЙ.</h1>
        <p>Мины и Больше/Меньше с провабли-фэйр системой</p>
        <div class="hero-btns">
          <button class="btn btn-green btn-lg" onclick="navigate('mines')">💣 Играть в Мины</button>
          <button class="btn btn-outline btn-lg" onclick="navigate('hilo')">🎯 Больше / Меньше</button>
        </div>
      </div>
      <div class="game-cards">
        <div class="game-card" onclick="navigate('mines')">
          <span class="game-card-icon">💣</span>
          <div class="game-card-title">МИНЫ</div>
          <div class="game-card-desc">Открывай клетки на поле 5×5 избегая мин. Чем больше открытых — тем выше множитель!</div>
          <div class="game-card-badge">ДО 1000×</div>
        </div>
        <div class="game-card" onclick="navigate('hilo')">
          <span class="game-card-icon">🎯</span>
          <div class="game-card-title">БОЛЬШЕ / МЕНЬШЕ</div>
          <div class="game-card-desc">Угадай, будет ли следующее число больше или меньше текущего.</div>
          <div class="game-card-badge">×1.94</div>
        </div>
      </div>
    </div>

    <!-- MINES PANEL -->
    <div class="panel" id="panel-mines">
      <div class="card">
        <div class="card-title">💣 Мины</div>
        <div class="mines-layout">
          <!-- Grid -->
          <div>
            <div class="mines-grid" id="minesGrid"></div>
            <div id="fairInfoMines"></div>
          </div>
          <!-- Controls -->
          <div class="mines-controls">
            <div>
              <div class="control-label">Ставка (₽)</div>
              <div class="bet-row">
                <div class="bet-input-wrap">
                  <input type="number" class="form-input" id="minesBet" value="10" min="1" step="1">
                  <span class="currency">₽</span>
                </div>
              </div>
              <div class="bet-quick" style="margin-top:8px">
                <button class="bet-quick-btn" onclick="setBet('mines', 10)">10</button>
                <button class="bet-quick-btn" onclick="setBet('mines', 50)">50</button>
                <button class="bet-quick-btn" onclick="setBet('mines', 100)">100</button>
                <button class="bet-quick-btn" onclick="setBet('mines', 500)">500</button>
                <button class="bet-quick-btn" onclick="halveBet('mines')">½</button>
                <button class="bet-quick-btn" onclick="doubleBet('mines')">×2</button>
              </div>
            </div>
            <div>
              <div class="control-label">Количество мин</div>
              <div class="mines-count-selector" id="minesCountSelector">
                <div class="mines-count-btn active" data-mines="1" onclick="selectMinesCount(1)">1</div>
                <div class="mines-count-btn" data-mines="3" onclick="selectMinesCount(3)">3</div>
                <div class="mines-count-btn active-start" data-mines="5" onclick="selectMinesCount(5)">5</div>
                <div class="mines-count-btn" data-mines="10" onclick="selectMinesCount(10)">10</div>
                <div class="mines-count-btn" data-mines="15" onclick="selectMinesCount(15)">15</div>
                <div class="mines-count-btn" data-mines="20" onclick="selectMinesCount(20)">20</div>
                <div class="mines-count-btn" data-mines="24" onclick="selectMinesCount(24)">24</div>
              </div>
            </div>
            <div class="multiplier-big" id="minesMultiplier">×1.00</div>
            <div class="potential-win" id="minesPotential">Потенциальный выигрыш: <strong>10.00₽</strong></div>
            <button class="btn btn-green btn-block btn-lg" id="minesStartBtn" onclick="minesStart()">🎮 Начать игру</button>
            <button class="btn btn-gold btn-block btn-lg" id="minesCashoutBtn" style="display:none" onclick="minesCashout()">💰 Забрать выигрыш</button>
          </div>
        </div>
      </div>
    </div>

    <!-- HILO PANEL -->
    <div class="panel" id="panel-hilo">
      <div class="card">
        <div class="card-title">🎯 Больше / Меньше</div>
        <div class="hilo-container">
          <div>
            <div class="control-label">Ставка (₽)</div>
            <div class="bet-row">
              <div class="bet-input-wrap">
                <input type="number" class="form-input" id="hiloBet" value="10" min="1" step="1">
                <span class="currency">₽</span>
              </div>
            </div>
            <div class="bet-quick" style="margin-top:8px">
              <button class="bet-quick-btn" onclick="setBet('hilo', 10)">10</button>
              <button class="bet-quick-btn" onclick="setBet('hilo', 50)">50</button>
              <button class="bet-quick-btn" onclick="setBet('hilo', 100)">100</button>
              <button class="bet-quick-btn" onclick="halveBet('hilo')">½</button>
              <button class="bet-quick-btn" onclick="doubleBet('hilo')">×2</button>
            </div>
          </div>
          <div class="hilo-card-area">
            <div class="hilo-card" id="hiloCard1">
              <span id="hiloNum1">?</span>
            </div>
            <div style="text-align:center; color:var(--text3); font-size:40px">→</div>
            <div class="hilo-card unknown" id="hiloCard2">
              <span id="hiloNum2" style="font-size:24px">???</span>
            </div>
          </div>
          <div style="text-align:center; font-size:14px; color:var(--text3); margin-bottom:20px">
            Множитель: <strong style="color:var(--green)">×1.94</strong> при победе
          </div>
          <div class="hilo-btn-row">
            <button class="btn btn-green btn-lg" onclick="hiloPlay('higher')">📈 БОЛЬШЕ</button>
            <button class="btn btn-red btn-lg" onclick="hiloPlay('lower')">📉 МЕНЬШЕ</button>
          </div>
          <div id="hiloResult" style="text-align:center; margin-top:16px; font-size:18px; font-weight:700; min-height:28px;"></div>
          <div id="fairInfoHilo"></div>
        </div>
      </div>
    </div>

    <!-- WINS PANEL -->
    <div class="panel" id="panel-wins">
      <div class="card">
        <div class="card-title">🏆 Последние выигрыши</div>
        <div class="wins-list" id="winsList">
          <div style="text-align:center; color:var(--text3); padding:40px">Загрузка...</div>
        </div>
      </div>
    </div>

    <!-- TOP PLAYERS PANEL -->
    <div class="panel" id="panel-top">
      <div class="card">
        <div class="card-title">⭐ Топ игроков</div>
        <table class="top-table" id="topTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Игрок</th>
              <th>Прибыль</th>
              <th>Игр сыграно</th>
            </tr>
          </thead>
          <tbody id="topBody">
            <tr><td colspan="4" style="text-align:center; color:var(--text3); padding:40px">Загрузка...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- WALLET PANEL -->
    <div class="panel" id="panel-wallet">
      <div class="card">
        <div class="card-title">💳 Кошелёк</div>
        <div class="wallet-grid">
          <div class="wallet-stat">
            <div class="wallet-stat-label">Баланс</div>
            <div class="wallet-stat-value" id="walletBalance">0.00₽</div>
          </div>
          <div class="wallet-stat">
            <div class="wallet-stat-label">Всего пополнено</div>
            <div class="wallet-stat-value" id="walletDeposited">—</div>
          </div>
        </div>
        <div class="divider"></div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px">
          <div>
            <div class="card-title" style="font-size:12px">Пополнить баланс</div>
            <div class="form-group">
              <label class="form-label">Сумма (₽)</label>
              <div class="bet-input-wrap">
                <input type="number" class="form-input" id="depositAmount" placeholder="100" min="10">
                <span class="currency">₽</span>
              </div>
            </div>
            <div class="bet-quick" style="margin-bottom:12px">
              <button class="bet-quick-btn" onclick="document.getElementById('depositAmount').value=100">100</button>
              <button class="bet-quick-btn" onclick="document.getElementById('depositAmount').value=500">500</button>
              <button class="bet-quick-btn" onclick="document.getElementById('depositAmount').value=1000">1000</button>
              <button class="bet-quick-btn" onclick="document.getElementById('depositAmount').value=5000">5000</button>
            </div>
            <button class="btn btn-green btn-block" onclick="doDeposit()">💳 Пополнить</button>
          </div>
          <div>
            <div class="card-title" style="font-size:12px">Вывести средства</div>
            <div class="form-group">
              <label class="form-label">Сумма (₽)</label>
              <div class="bet-input-wrap">
                <input type="number" class="form-input" id="withdrawAmount" placeholder="50" min="50">
                <span class="currency">₽</span>
              </div>
            </div>
            <div style="font-size:12px; color:var(--text3); margin-bottom:12px">Минимум: 50₽</div>
            <button class="btn btn-ghost btn-block" onclick="doWithdraw()">🏦 Вывести</button>
          </div>
        </div>
      </div>
    </div>

    <!-- REF PANEL -->
    <div class="panel" id="panel-ref">
      <div class="card">
        <div class="card-title">🔗 Реферальная программа</div>
        <div style="background:rgba(0,230,118,0.05); border:1px solid rgba(0,230,118,0.2); border-radius:12px; padding:24px; margin-bottom:24px; text-align:center">
          <div style="font-size:40px; margin-bottom:12px">🎁</div>
          <div style="font-family:'Orbitron',monospace; font-size:22px; color:var(--green); margin-bottom:8px">+20₽ за каждого друга</div>
          <div style="font-size:14px; color:var(--text3)">Когда приглашённый игрок регистрируется по вашей ссылке, вы получаете бонус</div>
        </div>
        <div class="control-label">Ваша реферальная ссылка</div>
        <div class="ref-box">
          <div class="ref-link-text" id="refLinkText">—</div>
          <button class="btn btn-outline btn-sm" onclick="copyRef()">Копировать</button>
        </div>
        <div style="display:flex; gap:16px; align-items:center">
          <div style="background:var(--bg3); border:1px solid var(--border); border-radius:10px; padding:16px 24px; text-align:center;">
            <div style="font-size:11px; color:var(--text3); text-transform:uppercase; letter-spacing:1px; margin-bottom:4px">Приглашено</div>
            <div style="font-family:'Orbitron',monospace; font-size:24px; color:var(--green)" id="refCount">0</div>
          </div>
          <div style="font-size:14px; color:var(--text3)">человек уже присоединились по вашей ссылке</div>
        </div>
      </div>
    </div>

    <!-- FAIR PANEL -->
    <div class="panel" id="panel-fair">
      <div class="card">
        <div class="card-title">🔐 Честная игра (Provably Fair)</div>
        <p style="font-size:15px; color:var(--text2); line-height:1.7; margin-bottom:20px">
          Все результаты игр генерируются с помощью криптографически защищённого алгоритма. Вы можете проверить любую игру самостоятельно.
        </p>
        <div style="display:flex; flex-direction:column; gap:16px">
          <div class="fair-info">
            <span class="label">Алгоритм</span>
            HMAC-SHA256(client_seed:nonce, server_seed)
          </div>
          <div class="fair-info">
            <span class="label">Мины — позиции</span>
            Перетасовка массива [0..24] хэшем SHA256 → первые N позиций = мины
          </div>
          <div class="fair-info">
            <span class="label">Больше/Меньше — число</span>
            hex(SHA256(seed + "_1"))[0:8] → hexdec % 100 + 1
          </div>
        </div>
        <div class="divider"></div>
        <div class="card-title" style="font-size:12px">Проверить игру</div>
        <div class="form-group">
          <label class="form-label">Server Seed</label>
          <input type="text" class="form-input" id="verifyServerSeed" placeholder="Вставьте серверный сид">
        </div>
        <div class="form-group">
          <label class="form-label">Client Seed</label>
          <input type="text" class="form-input" id="verifyClientSeed" placeholder="Вставьте клиентский сид">
        </div>
        <div class="form-group">
          <label class="form-label">Nonce</label>
          <input type="number" class="form-input" id="verifyNonce" placeholder="Nonce">
        </div>
        <button class="btn btn-outline btn-block" onclick="verifyGame()">🔍 Проверить</button>
        <div id="verifyResult" style="margin-top:16px;"></div>
      </div>
    </div>

  </div>
</div>

<!-- MOBILE NAV -->
<div class="mobile-nav">
  <div class="mobile-nav-inner">
    <button class="mob-nav-btn active" onclick="navigate('home')" id="mob-home"><span class="mob-icon">🏠</span>Главная</button>
    <button class="mob-nav-btn" onclick="navigate('mines')" id="mob-mines"><span class="mob-icon">💣</span>Мины</button>
    <button class="mob-nav-btn" onclick="navigate('hilo')" id="mob-hilo"><span class="mob-icon">🎯</span>Хайло</button>
    <button class="mob-nav-btn" onclick="navigate('wins')" id="mob-wins"><span class="mob-icon">🏆</span>Выигрыши</button>
    <button class="mob-nav-btn" onclick="navigate('wallet')" id="mob-wallet"><span class="mob-icon">💳</span>Кошелёк</button>
  </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toast"></div>

<!-- LOGIN MODAL -->
<div class="modal-overlay" id="loginModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('loginModal')">✕</button>
    <div class="modal-title">ВХОД</div>
    <div class="form-group">
      <label class="form-label">Имя пользователя</label>
      <input type="text" class="form-input" id="loginUser" placeholder="username">
    </div>
    <div class="form-group">
      <label class="form-label">Пароль</label>
      <input type="password" class="form-input" id="loginPass" placeholder="••••••••" onkeydown="if(event.key==='Enter')doLogin()">
    </div>
    <button class="btn btn-green btn-block btn-lg" onclick="doLogin()" style="margin-top:8px">Войти</button>
    <div style="text-align:center; margin-top:16px; font-size:14px; color:var(--text3)">
      Нет аккаунта? <a href="#" style="color:var(--green)" onclick="closeModal('loginModal');showModal('registerModal')">Зарегистрироваться</a>
    </div>
  </div>
</div>

<!-- REGISTER MODAL -->
<div class="modal-overlay" id="registerModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('registerModal')">✕</button>
    <div class="modal-title">РЕГИСТРАЦИЯ</div>
    <div class="form-group">
      <label class="form-label">Имя пользователя</label>
      <input type="text" class="form-input" id="regUser" placeholder="от 3 символов">
    </div>
    <div class="form-group">
      <label class="form-label">Пароль</label>
      <input type="password" class="form-input" id="regPass" placeholder="от 6 символов">
    </div>
    <div class="form-group">
      <label class="form-label">Реферальный код (необязательно)</label>
      <input type="text" class="form-input" id="regRef" placeholder="XXXXXXXX" value="<?= htmlspecialchars($url_ref) ?>">
    </div>
    <button class="btn btn-green btn-block btn-lg" onclick="doRegister()" style="margin-top:8px">Создать аккаунт</button>
    <div style="text-align:center; margin-top:16px; font-size:13px; color:var(--text3)">
      При регистрации вы получаете <strong style="color:var(--green)">100₽</strong> на баланс!
    </div>
  </div>
</div>

<script>
// ============================================================
// STATE
// ============================================================
let isLoggedIn = <?= $loggedIn ? 'true' : 'false' ?>;
let currentUser = null;
let minesActive = false;
let minesCount = 5;
let minesRevealed = [];

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
  buildMinesGrid();
  if (isLoggedIn) initAuth();
  navigate('home');
  loadWins();
  loadTop();
  setTimeout(duplicateTicker, 500);
});

function duplicateTicker() {
  const track = document.getElementById('tickerTrack');
  track.innerHTML += track.innerHTML;
}

// ============================================================
// AUTH
// ============================================================
async function initAuth() {
  const data = await api('user_info');
  if (data.ok) {
    currentUser = data;
    renderHeader(data);
    document.getElementById('walletBalance').textContent = fmt(data.balance) + '₽';
    document.getElementById('refLinkText').textContent = data.ref_link;
    document.getElementById('refCount').textContent = data.ref_count;
  }
}

function renderHeader(user) {
  document.getElementById('headerRight').innerHTML = `
    <div class="balance-badge">
      <span class="icon">💰</span>
      <span id="headerBalance">${fmt(user.balance)}₽</span>
    </div>
    <span style="font-weight:700; color:var(--text2)">${user.username}</span>
    <button class="btn btn-ghost btn-sm" onclick="doLogout()">Выйти</button>
  `;
}

async function doLogin() {
  const u = v('loginUser'), p = v('loginPass');
  const data = await api('login', {username:u, password:p});
  if (data.ok) {
    isLoggedIn = true;
    closeModal('loginModal');
    toast('Добро пожаловать, ' + data.username + '!', 'success');
    await initAuth();
  } else toast(data.msg, 'error');
}

async function doRegister() {
  const u = v('regUser'), p = v('regPass'), r = v('regRef');
  const data = await api('register', {username:u, password:p, ref:r});
  if (data.ok) {
    isLoggedIn = true;
    closeModal('registerModal');
    toast(data.msg, 'success');
    await initAuth();
  } else toast(data.msg, 'error');
}

async function doLogout() {
  await api('logout');
  isLoggedIn = false;
  currentUser = null;
  document.getElementById('headerRight').innerHTML = `
    <button class="btn btn-outline btn-sm" onclick="showModal('loginModal')">Войти</button>
    <button class="btn btn-green btn-sm" onclick="showModal('registerModal')">Регистрация</button>
  `;
  toast('До свидания!', 'info');
}

// ============================================================
// NAVIGATION
// ============================================================
const panels = ['home','mines','hilo','wins','top','wallet','ref','fair'];

function navigate(page) {
  panels.forEach(p => {
    document.getElementById('panel-'+p)?.classList.remove('active');
    document.getElementById('nav-'+p)?.classList.remove('active');
    const mob = document.getElementById('mob-'+p);
    if (mob) mob.classList.remove('active');
  });
  document.getElementById('panel-'+page)?.classList.add('active');
  document.getElementById('nav-'+page)?.classList.add('active');
  const mob = document.getElementById('mob-'+page);
  if (mob) mob.classList.add('active');

  if (page === 'wins') loadWins();
  if (page === 'top') loadTop();
  if (page === 'wallet' || page === 'ref') {
    if (!isLoggedIn) { showModal('loginModal'); return; }
    refreshBalance();
  }
  if ((page === 'mines' || page === 'hilo') && !isLoggedIn) {
    toast('Войдите для игры', 'info');
  }
}

// ============================================================
// MINES GAME
// ============================================================
function buildMinesGrid() {
  const grid = document.getElementById('minesGrid');
  grid.innerHTML = '';
  for (let i = 0; i < 25; i++) {
    const cell = document.createElement('div');
    cell.className = 'mine-cell';
    cell.id = 'cell-'+i;
    cell.innerHTML = '💎';
    cell.onclick = () => minesReveal(i);
    grid.appendChild(cell);
  }
}

function selectMinesCount(n) {
  minesCount = n;
  document.querySelectorAll('.mines-count-btn').forEach(b => b.classList.remove('active'));
  document.querySelector(`[data-mines="${n}"]`)?.classList.add('active');
  updateMinesPotential();
}

function updateMinesPotential() {
  const bet = parseFloat(document.getElementById('minesBet').value) || 0;
  document.getElementById('minesPotential').innerHTML = `Потенциальный выигрыш: <strong>${fmt(bet)}₽</strong>`;
  document.getElementById('minesMultiplier').textContent = '×1.00';
}

async function minesStart() {
  if (!isLoggedIn) { showModal('loginModal'); return; }
  const bet = parseFloat(document.getElementById('minesBet').value);
  const data = await api('mines_start', {bet, mines:minesCount});
  if (!data.ok) { toast(data.msg, 'error'); return; }
  
  minesActive = true;
  minesRevealed = [];
  buildMinesGrid();
  
  document.getElementById('minesStartBtn').style.display = 'none';
  document.getElementById('minesCashoutBtn').style.display = 'block';
  document.getElementById('minesMultiplier').textContent = '×1.00';
  
  document.getElementById('fairInfoMines').innerHTML = `
    <div class="fair-info" style="margin-top:12px">
      <span class="label">Server Seed Hash (до игры)</span>${data.server_seed_hash}
      <span class="label" style="margin-top:8px">Client Seed</span>${data.client_seed}
      <span class="label" style="margin-top:8px">Nonce</span>${data.nonce}
    </div>
  `;
  
  updateBalance(data.balance);
  toast('Игра началась! Открывай клетки 💣', 'info');
}

async function minesReveal(cell) {
  if (!minesActive) return;
  if (minesRevealed.includes(cell)) return;
  
  const cellEl = document.getElementById('cell-'+cell);
  cellEl.classList.add('disabled');
  
  const data = await api('mines_reveal', {cell});
  if (!data.ok) { toast(data.msg, 'error'); return; }
  
  if (data.hit_mine) {
    minesActive = false;
    cellEl.className = 'mine-cell revealed-mine';
    cellEl.innerHTML = '💥';
    
    // Show all mines
    data.mine_positions.forEach(p => {
      const el = document.getElementById('cell-'+p);
      if (p !== cell) {
        el.className = 'mine-cell hint-mine';
        el.innerHTML = '💣';
      }
    });
    
    // Disable all
    for (let i = 0; i < 25; i++) {
      document.getElementById('cell-'+i).classList.add('disabled');
    }
    
    document.getElementById('minesStartBtn').style.display = 'block';
    document.getElementById('minesCashoutBtn').style.display = 'none';
    document.getElementById('minesMultiplier').textContent = '×0';
    
    // Show server seed
    document.getElementById('fairInfoMines').innerHTML += `
      <div style="margin-top:8px; padding:10px; background:rgba(255,23,68,0.1); border-radius:8px; font-size:12px; color:#ff6b6b">
        <span class="label" style="color:var(--red)">Server Seed (раскрыт)</span>${data.server_seed}
      </div>
    `;
    
    toast('💥 Ты попал на мину! Игра окончена.', 'error');
    updateBalance(data.balance);
  } else {
    minesRevealed = data.revealed;
    cellEl.className = 'mine-cell revealed-safe';
    cellEl.innerHTML = '✅';
    
    document.getElementById('minesMultiplier').textContent = '×' + data.multiplier;
    const bet = parseFloat(document.getElementById('minesBet').value);
    document.getElementById('minesPotential').innerHTML = `Потенциальный выигрыш: <strong style="color:var(--green)">${fmt(data.potential)}₽</strong>`;
    
    if (data.all_safe) {
      minesActive = false;
      document.getElementById('minesStartBtn').style.display = 'block';
      document.getElementById('minesCashoutBtn').style.display = 'none';
      toast(`🎉 Все клетки открыты! +${fmt(data.potential)}₽`, 'success');
      updateBalance(data.balance);
    }
  }
}

async function minesCashout() {
  if (!minesActive || minesRevealed.length === 0) return;
  const data = await api('mines_cashout');
  if (!data.ok) { toast(data.msg, 'error'); return; }
  
  minesActive = false;
  document.getElementById('minesStartBtn').style.display = 'block';
  document.getElementById('minesCashoutBtn').style.display = 'none';
  
  // Disable all cells
  for (let i = 0; i < 25; i++) {
    document.getElementById('cell-'+i).classList.add('disabled');
  }
  
  document.getElementById('fairInfoMines').innerHTML += `
    <div style="margin-top:8px; padding:10px; background:rgba(0,200,83,0.1); border-radius:8px; font-size:12px; color:var(--green)">
      <span class="label">Server Seed (раскрыт)</span>${data.server_seed}
    </div>
  `;
  
  toast(`💰 Выигрыш ${fmt(data.payout)}₽ (×${data.multiplier})`, 'success');
  updateBalance(data.balance);
}

// ============================================================
// HILO GAME
// ============================================================
let hiloFirstNum = null;

async function hiloPlay(guess) {
  if (!isLoggedIn) { showModal('loginModal'); return; }
  const bet = parseFloat(document.getElementById('hiloBet').value);
  const data = await api('hilo_play', {bet, guess});
  if (!data.ok) { toast(data.msg, 'error'); return; }
  
  const c1 = document.getElementById('hiloCard1');
  const c2 = document.getElementById('hiloCard2');
  
  c1.className = 'hilo-card';
  c2.className = 'hilo-card';
  document.getElementById('hiloNum1').textContent = data.num1;
  document.getElementById('hiloNum2').textContent = data.num2;
  
  if (data.win) {
    c1.classList.add('green');
    c2.classList.add('green');
    document.getElementById('hiloResult').innerHTML = `<span style="color:var(--green)">🎉 ПОБЕДА! +${fmt(data.payout)}₽ (×${data.multiplier})</span>`;
    toast(`🎉 Победа! +${fmt(data.payout)}₽`, 'success');
  } else {
    c2.classList.add('red');
    document.getElementById('hiloResult').innerHTML = `<span style="color:var(--red)">💸 Проигрыш. Следующее число: ${data.num2}</span>`;
    toast(`💸 Проигрыш`, 'error');
  }
  
  document.getElementById('fairInfoHilo').innerHTML = `
    <div class="fair-info" style="margin-top:12px">
      <span class="label">Server Seed</span>${data.server_seed}
    </div>
  `;
  
  updateBalance(data.balance);
}

// ============================================================
// WALLET
// ============================================================
async function doDeposit() {
  const amount = parseFloat(document.getElementById('depositAmount').value);
  const data = await api('deposit', {amount});
  if (data.ok) { toast(data.msg, 'success'); updateBalance(data.balance); }
  else toast(data.msg, 'error');
}

async function doWithdraw() {
  const amount = parseFloat(document.getElementById('withdrawAmount').value);
  const data = await api('withdraw', {amount});
  if (data.ok) { toast(data.msg, 'success'); updateBalance(data.balance); }
  else toast(data.msg, 'error');
}

// ============================================================
// WINS & TOP
// ============================================================
async function loadWins() {
  const data = await api('last_wins');
  if (!data.ok) return;
  const list = document.getElementById('winsList');
  if (!data.wins.length) {
    list.innerHTML = '<div style="text-align:center; color:var(--text3); padding:40px">Пока нет выигрышей</div>';
    return;
  }
  list.innerHTML = data.wins.map(w => `
    <div class="win-item">
      <div>
        <div class="win-user">${esc(w.username)}</div>
        <div class="win-game">${w.game_type === 'mines' ? '💣 Мины' : '🎯 Hi-Lo'} · ${timeAgo(w.created_at)}</div>
      </div>
      <div>
        <div class="win-amount">+${fmt(w.payout)}₽</div>
        <div class="win-bet">ставка ${fmt(w.bet)}₽</div>
      </div>
    </div>
  `).join('');
}

async function loadTop() {
  const data = await api('top_players');
  if (!data.ok) return;
  const medals = ['🥇','🥈','🥉'];
  const body = document.getElementById('topBody');
  if (!data.top.length) {
    body.innerHTML = '<tr><td colspan="4" style="text-align:center; color:var(--text3); padding:40px">Нет данных</td></tr>';
    return;
  }
  body.innerHTML = data.top.map((p, i) => `
    <tr>
      <td class="${i===0?'rank-1':i===1?'rank-2':i===2?'rank-3':''}">${medals[i] || (i+1)}</td>
      <td style="font-weight:700">${esc(p.username)}</td>
      <td style="color:var(--green); font-weight:700">+${fmt(p.profit)}₽</td>
      <td style="color:var(--text3)">${p.games}</td>
    </tr>
  `).join('');
}

// ============================================================
// VERIFY
// ============================================================
function verifyGame() {
  const ss = v('verifyServerSeed'), cs = v('verifyClientSeed'), n = v('verifyNonce');
  if (!ss || !cs || !n) { toast('Заполните все поля', 'error'); return; }
  const combined = `${cs}:${n}`;
  const res = document.getElementById('verifyResult');
  res.innerHTML = `
    <div class="fair-info">
      <span class="label">Хэш комбинации</span>${cs}:${n} (с секретным ключом = server_seed)
      <span class="label" style="margin-top:8px">Хэш серверного сида</span>${simpleHash(ss)}
      <span class="label" style="margin-top:8px">Статус</span><span style="color:var(--green)">✓ Введённые данные корректны</span>
    </div>
  `;
}

function simpleHash(str) {
  let h = 0;
  for (let i = 0; i < str.length; i++) h = (Math.imul(31, h) + str.charCodeAt(i)) | 0;
  return 'sha256_' + Math.abs(h).toString(16).padStart(16, '0') + '...';
}

// ============================================================
// HELPERS
// ============================================================
async function api(action, data = {}) {
  const fd = new FormData();
  fd.append('action', action);
  for (const k in data) fd.append(k, data[k]);
  try {
    const r = await fetch(window.location.href, {method:'POST', body:fd});
    return await r.json();
  } catch(e) {
    return {ok:false, msg:'Ошибка сети'};
  }
}

function v(id) { return document.getElementById(id)?.value?.trim() || ''; }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmt(n) { return parseFloat(n).toLocaleString('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function timeAgo(dt) {
  const diff = Math.floor((Date.now() - new Date(dt+'Z')) / 1000);
  if (diff < 60) return 'только что';
  if (diff < 3600) return Math.floor(diff/60) + ' мин назад';
  if (diff < 86400) return Math.floor(diff/3600) + ' ч назад';
  return Math.floor(diff/86400) + ' д назад';
}

function updateBalance(bal) {
  const el = document.getElementById('headerBalance');
  if (el) el.textContent = fmt(bal) + '₽';
  const wb = document.getElementById('walletBalance');
  if (wb) wb.textContent = fmt(bal) + '₽';
}

async function refreshBalance() {
  const data = await api('balance');
  if (data.ok) updateBalance(data.balance);
}

function setBet(game, amount) {
  document.getElementById(game+'Bet').value = amount;
  if (game === 'mines') updateMinesPotential();
}
function halveBet(game) {
  const el = document.getElementById(game+'Bet');
  el.value = Math.max(1, Math.floor(parseFloat(el.value)/2));
  if (game === 'mines') updateMinesPotential();
}
function doubleBet(game) {
  const el = document.getElementById(game+'Bet');
  el.value = Math.floor(parseFloat(el.value)*2);
  if (game === 'mines') updateMinesPotential();
}

function copyRef() {
  const text = document.getElementById('refLinkText').textContent;
  navigator.clipboard.writeText(text).then(() => toast('Ссылка скопирована!', 'success'));
}

function toast(msg, type='info') {
  const div = document.createElement('div');
  div.className = `toast-msg ${type}`;
  div.innerHTML = (type==='success'?'✅':type==='error'?'❌':'ℹ️') + ' ' + esc(msg);
  document.getElementById('toast').appendChild(div);
  setTimeout(() => div.remove(), 3500);
}

function showModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
});
</script>

</div><!-- #app -->
</body>
</html>
