<?php

declare(strict_types=1);

return [

    // 403 when the user has no application role (login and keycloak.role)
    'denied' => 'Access denied: ask an administrator for a role in the application.',

    // Fake user name in bypass mode
    'bypass_name' => 'Local developer',

    // Sign-in page from the keycloak-welcome stub. :app: config('app.name')
    'welcome' => [
        'title' => 'Sign in',
        'sign_in' => 'Sign in',
        'hint' => 'Use the username and password you sign in to your computer with.',
        'notice_signed_out' => 'You have signed out of :app.',
        'notice_session_expired' => 'The sign-in session expired while the sign-in form was open. Click “Sign in” again.',
        'notice_login_failed' => 'Sign-in was not completed. Click “Sign in” to try again.',
    ],

];
