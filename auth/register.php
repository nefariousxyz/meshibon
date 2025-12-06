<?php
session_start();
require '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $stmt->execute([$username, $password]);

    $_SESSION['user'] = ['username' => $username];
    header("Location: ../dashboard.php");
    exit;
}
?>

<!-- Tailwind CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<form method="POST" class="max-w-md mx-auto mt-10 p-5 border rounded-lg shadow">
  <h2 class="text-2xl font-bold mb-4">Register</h2>
  <input name="username" placeholder="Username" class="w-full p-2 border mb-3 rounded" required>
  <input type="password" name="password" placeholder="Password" class="w-full p-2 border mb-3 rounded" required>
  <button class="bg-blue-600 text-white px-4 py-2 rounded w-full">Register</button>
</form>
<p class="text-center mt-4"><a href="login.php" class="text-blue-600">Already have an account? Login</a></p>
