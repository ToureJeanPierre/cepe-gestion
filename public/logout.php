<?php
require_once '../config/database.php';

unset($_SESSION['utilisateur_id'], $_SESSION['utilisateur_nom']);
session_regenerate_id(true);

header('Location: login.php');
exit;
