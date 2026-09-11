<?php
// api/index.php
declare(strict_types=1);

define('APP_ROOT', __DIR__);

// Load env from .env file if it exists (shared hosting convenience)
if (file_exists(APP_ROOT . '/.env')) {
    foreach (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = preg_replace('/[^A-Z0-9_]/i', '', trim($key));
        if ($key !== '') putenv("$key=" . trim($val));
    }
}

require_once APP_ROOT . '/src/Config/Database.php';
require_once APP_ROOT . '/src/Helpers/JWT.php';
require_once APP_ROOT . '/src/Helpers/Response.php';
require_once APP_ROOT . '/src/Router.php';
require_once APP_ROOT . '/src/Middleware/AuthMiddleware.php';
require_once APP_ROOT . '/src/Models/User.php';
require_once APP_ROOT . '/src/Models/Session.php';
require_once APP_ROOT . '/src/Models/Conversation.php';
require_once APP_ROOT . '/src/Models/Message.php';
require_once APP_ROOT . '/src/Models/UserChatState.php';
require_once APP_ROOT . '/src/Controllers/AuthController.php';
require_once APP_ROOT . '/src/Controllers/UserController.php';
require_once APP_ROOT . '/src/Controllers/ConversationController.php';
require_once APP_ROOT . '/src/Controllers/MessageController.php';
require_once APP_ROOT . '/src/Controllers/UpdateController.php';
require_once APP_ROOT . '/src/Models/Group.php';
require_once APP_ROOT . '/src/Controllers/GroupController.php';
require_once APP_ROOT . '/src/Controllers/MediaController.php';
require_once APP_ROOT . '/src/Models/Reaction.php';
require_once APP_ROOT . '/src/Controllers/ReactionController.php';
require_once APP_ROOT . '/src/Controllers/SettingsController.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$router = new Router();

$router->post('/auth/check',    [AuthController::class, 'check']);
$router->post('/auth/register', [AuthController::class, 'register']);
$router->post('/auth/login',    [AuthController::class, 'login']);
$router->post('/auth/logout',   [AuthController::class, 'logout'], auth: true);

// Users
$router->patch('/profile',    [UserController::class, 'updateProfile'], auth: true);
$router->get('/users/search', [UserController::class, 'search'], auth: true);

// Conversations
$router->get('/conversations',          [ConversationController::class, 'index'],    auth: true);
$router->post('/conversations',         [ConversationController::class, 'create'],   auth: true);
$router->patch('/conversations/:id/read', [ConversationController::class, 'markRead'], auth: true);

// Messages
$router->get('/messages',        [MessageController::class, 'index'],   auth: true);
$router->post('/messages',       [MessageController::class, 'send'],    auth: true);
$router->patch('/messages/:id',  [MessageController::class, 'edit'],    auth: true);
$router->delete('/messages/:id', [MessageController::class, 'destroy'], auth: true);

// Groups
$router->get('/groups',                    [GroupController::class, 'index'],      auth: true);
$router->post('/groups',                   [GroupController::class, 'create'],     auth: true);
$router->patch('/groups/:id',              [GroupController::class, 'update'],     auth: true);
$router->get('/groups/:id/members',        [GroupController::class, 'members'],    auth: true);
$router->post('/groups/:id/members',       [GroupController::class, 'addMember'],  auth: true);
$router->delete('/groups/:id/members/:memberId', [GroupController::class, 'removeMember'], auth: true);
$router->patch('/groups/:id/read',         [GroupController::class, 'markRead'],   auth: true);
$router->get('/groups/:id/read-receipts',          [GroupController::class, 'readReceipts'],auth: true);
$router->get('/groups/:id/messages',              [GroupController::class, 'getMessages'], auth: true);
$router->post('/groups/:id/messages',             [GroupController::class, 'sendMessage'], auth: true);
$router->patch('/groups/:id/messages/:msgId',     [GroupController::class, 'editMessage'], auth: true);

// Media
$router->post('/media/upload',      [MediaController::class, 'upload'], auth: true);
$router->get('/media/:file_id',     [MediaController::class, 'serve'],  auth: true);

// Reactions
$router->get('/reactions/batch',                [ReactionController::class, 'batch'],  auth: true);
$router->get('/messages/:id/reactions',         [ReactionController::class, 'index'],  auth: true);
$router->post('/messages/:id/reactions',        [ReactionController::class, 'add'],    auth: true);
$router->delete('/messages/:id/reactions/:emoji', [ReactionController::class, 'remove'], auth: true);

// Settings
$router->get('/settings', [SettingsController::class, 'index'],  auth: true);
$router->put('/settings', [SettingsController::class, 'update'], auth: true);

// Polling
$router->get('/updates', [UpdateController::class, 'poll'], auth: true);

$router->dispatch();
