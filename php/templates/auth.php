<main class="prototype-form-page"><p class="eyebrow">YOUR COMMUNITY ACCOUNT</p><h1><?= $path==='/register' ? 'Create an account' : 'Welcome back' ?></h1>
<p class="help">Sign in with your existing gallery username and password if the account has been imported. Account and password changes apply to this gallery only.</p>
<?php if ($formError): ?><p class="form-error" role="alert"><?= e($formError) ?></p><?php endif; ?>
<?php if ($path==='/register' && !site_settings()['registrationOpen']): ?><p class="notice">Registration is currently paused.</p><?php else: ?><form method="post" class="prototype-form" action="<?= e($path) ?>"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e(login_destination(param($_POST,'next',param($_GET,'next')))) ?>">
<label>Username<input name="username" autocomplete="username" value="<?= e(param($_POST,'username')) ?>" <?= $path==='/register' ? 'minlength="3" maxlength="32"' : 'maxlength="128"' ?> required></label>
<label>Password<input type="password" name="password" autocomplete="<?= $path==='/register' ? 'new-password' : 'current-password' ?>" <?= $path==='/register' ? 'minlength="12" maxlength="72"' : '' ?> required></label>
<?php if ($path==='/register'): ?><p class="help">Use 12–72 bytes for your password. Existing gallery names are reserved. Use Sign in for your existing community account.</p><?php endif; ?>
<button type="submit"><?= $path==='/register' ? 'Create account' : 'Sign in' ?></button>
</form><?php endif; ?><p><a href="<?= $path==='/register' ? '/login' : '/register' ?>"><?= $path==='/register' ? 'Already registered? Sign in →' : 'Create an account →' ?></a></p></main>
