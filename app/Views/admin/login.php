<?php /** @var string|null $error */ ?>
<div class="login-card">
  <h1>Sign in</h1>
  <p>Admin panel</p>

  <?php if (!empty($error)): ?>
    <div class="flash flash--error"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" action="/admin/login">
    <div class="field">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autocomplete="username" autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button class="btn btn--primary" type="submit" style="width:100%; justify-content:center">Sign in</button>
  </form>
</div>
