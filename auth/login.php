<?php
// /auth/login.php

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

// Si ya está logueado, redirige al listado
if (Auth::check()) {
    header('Location: ../leads/list.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u || !password_verify($password, $u['password_hash'])) {
        // Usuario no existe o contraseña incorrecta
        $error = 'Credenciales inválidas.';
    }
    elseif ($u['status'] !== 'active') {
        // Usuario existe pero está inactivo
        $error = 'Cuenta inactiva. Por favor contacte al administrador.';
    }
    else {
        // Autenticación correcta y usuario activo: iniciar sesión
        $_SESSION['user'] = [
            'id'    => $u['id'],
            'name'  => $u['name'],
            'email' => $u['email'],
        ];

        // Cargar roles desde la BD
        $stmt = $pdo->prepare("
            SELECT r.name 
              FROM roles r
              JOIN user_roles ur ON r.id = ur.role_id
             WHERE ur.user_id = ?
        ");
        $stmt->execute([$u['id']]);
        $_SESSION['user']['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        header('Location: ../leads/list.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Iniciar Sesión</title>
  <link rel="stylesheet" href="./../assets/css/auth/login.css">
</head>
<body>

  <div class="login-container">
    <h1>Iniciar Sesión</h1>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" class="login-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="form-group">
        <label for="email">Email</label>
        <input 
          type="email" 
          id="email" 
          name="email" 
          required 
          class="form-control"
          value="<?= htmlspecialchars($email ?? '') ?>"
        >
      </div>

      <div class="form-group">
        <label for="password">Contraseña</label>
        <input 
          type="password" 
          id="password" 
          name="password" 
          required 
          class="form-control"
        >
      </div>

      <button type="submit" class="btn">Entrar</button>
    </form>
  </div>

</body>
</html>
