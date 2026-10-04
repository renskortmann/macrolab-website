<?php

declare(strict_types=1);

/**
 * The whole route table. Read top to bottom, it is also the shortest summary
 * of what the application does.
 */

use Macrolab\Controller\AccountController;
use Macrolab\Controller\AdminController;
use Macrolab\Controller\AdminTimeController;
use Macrolab\Controller\AuthController;
use Macrolab\Controller\BookingApiController;
use Macrolab\Controller\CalendarController;
use Macrolab\Controller\HubController;
use Macrolab\Controller\TimeApiController;
use Macrolab\Controller\TimeController;
use Macrolab\Router;

$router = new Router();

// ------------------------------------------------------------------- macrolab
// The front door. Signing in lands here and picks a system from it.
$router->get('/', [HubController::class, 'show']);

// ------------------------------------------------------------------- calendar
$router->get('/booking', [CalendarController::class, 'show']);

// The JSON API the calendar talks to. Every write verifies the CSRF token and
// re-checks ownership against the stored booking.
$router->get('/api/bookings', [BookingApiController::class, 'feed']);
$router->post('/api/bookings', [BookingApiController::class, 'create']);
$router->post('/api/bookings/{id}', [BookingApiController::class, 'update']);
$router->post('/api/bookings/{id}/cancel', [BookingApiController::class, 'cancel']);

// ---------------------------------------------------------- time registration
// Unrelated to the booking system above; the two share only the sign-in.
$router->get('/time', [TimeController::class, 'show']);
// Everyone's time, for lab managers - the same page and export the
// administrator has at /admin/time. Before /time/{id}, which would otherwise
// take "overview" for an entry id.
$router->get('/time/overview', [AdminTimeController::class, 'entries']);
$router->get('/time/overview.csv', [AdminTimeController::class, 'export']);
$router->form('/time/{id}', [TimeController::class, 'edit']);
$router->post('/time/{id}/delete', [TimeController::class, 'delete']);

// The day sheet saves each cell as it is left. Under /api so the {id} route
// above never sees these, and so errors come back as JSON.
$router->post('/api/time/cell', [TimeApiController::class, 'saveCell']);
$router->get('/api/time/month', [TimeApiController::class, 'month']);

// --------------------------------------------------------------------- access
$router->form('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->form('/setup/{token}', [AuthController::class, 'setup']);
$router->form('/account', [AccountController::class, 'show']);

// Stage 2 - TU Delft SSO, not built yet: these answer 404 for now. The paths
// are reserved so that the service provider metadata we register with ICT
// never has to change.
$router->get('/auth/saml/login', [AuthController::class, 'ssoNotEnabled']);
$router->post('/auth/saml/acs', [AuthController::class, 'ssoNotEnabled']);
$router->get('/auth/saml/sls', [AuthController::class, 'ssoNotEnabled']);
$router->get('/auth/saml/metadata', [AuthController::class, 'ssoNotEnabled']);

// ---------------------------------------------------------------------- admin
$router->form('/admin/login', [AdminController::class, 'login']);
$router->form('/admin/login/2fa', [AdminController::class, 'twoFactor']);
$router->post('/admin/logout', [AdminController::class, 'logout']);
$router->get('/admin', [AdminController::class, 'dashboard']);
$router->form('/admin/users', [AdminController::class, 'users']);
$router->form('/admin/machines', [AdminController::class, 'machines']);
$router->form('/admin/bookings', [AdminController::class, 'bookings']);
$router->form('/admin/settings', [AdminController::class, 'settings']);
$router->get('/admin/audit', [AdminController::class, 'audit']);

// The project list, and the read-only view of what everyone has logged. The
// dot in the export path is literal: Router::compile() quotes the pattern.
$router->form('/admin/projects', [AdminTimeController::class, 'projects']);
$router->get('/admin/time', [AdminTimeController::class, 'entries']);
$router->get('/admin/time.csv', [AdminTimeController::class, 'export']);

$router->form('/admin/system', [AdminController::class, 'system']);

// Creates the administrator account, then stops existing.
$router->form('/install', [AdminController::class, 'install']);

return $router;
