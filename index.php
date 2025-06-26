<?php
// Load config if exists
if (file_exists('config.php')) {
    require_once 'config.php';
}

if (!defined('DB_PATH')) {
    define('DB_PATH', 'notedb.sqlite');
}

$welcomePageName = 'Welcome';

// Notes content to insert if missing
$welcomeNotes = [
    "## Welcome to `notd`! 👋\nThis is your first note. You can use this space to jot down your thoughts, ideas, or anything else you want to remember.",
    "## Task Management 📝\nYou can create task lists by starting your notes with the special keywords: `TODO`, `DOING`, `DONE`, `WAITING`, `CANCELLED`, `SOMEDAY`, `NLR` (no longer relevant).",
    "## Shortcuts ⌨️\nUse text expanders to quickly add properties such as `:t` tags or `:k` keywords, `:d` date or `:d` timestamp.",
    "## Properties ✨\nAdd properties to your notes to organize them further. For example, you can add a {priority::high} property to important notes, set a {favorite::true} or add a {tag::tag}.",
    "## Templates 🎨\nUse templates to quickly create notes with a consistent format. Just write `/` to get started.",
    "## Encryption 🔒\nEnable encryption for your notes through page properties. Note that this is an experimental feature. After enabling encryption, you'll need to reload the page before adding new notes.",
    "## SQL Queries 🔍\nYou can run SQL queries to get data from your database. Just write `SQL {SELECT * FROM Notes WHERE page_id = 1}` (remove space after `SQL`) to get started."
];

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $stmt = $pdo->prepare("SELECT id, name FROM Pages WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$welcomePageName]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$page) {
        // Create welcome page if not exists
        $pdo->beginTransaction();
        $insertPage = $pdo->prepare("INSERT INTO Pages (name, created_at, updated_at) VALUES (?, datetime('now'), datetime('now'))");
        $insertPage->execute([$welcomePageName]);
        $pageId = $pdo->lastInsertId();
        $pdo->commit();
    } else {
        $pageId = $page['id'];
        $pageNameToRedirect = $page['name'];

        // Delete existing notes for Welcome page
        $deleteNotes = $pdo->prepare("DELETE FROM Notes WHERE page_id = ?");
        $deleteNotes->execute([$pageId]);
    }

    // Insert updated welcome notes
    $pdo->beginTransaction();
    $insertNote = $pdo->prepare("INSERT INTO Notes (page_id, content, created_at, updated_at) VALUES (?, ?, datetime('now'), datetime('now'))");
    foreach ($welcomeNotes as $content) {
        $insertNote->execute([$pageId, $content]);
    }
    $pdo->commit();

    header('Location: page.php?page=' . urlencode($welcomePageName));
    exit;

} catch (Exception $e) {
    error_log('Error in index.php: ' . $e->getMessage());
    header('Location: page.php?page=' . urlencode($welcomePageName));
    exit;
}
