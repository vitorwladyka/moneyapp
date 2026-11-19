<?php
declare(strict_types=1);

/**
 * Header compartilhado.
 * Recebe $active (id da seção atual) e $title (título da página).
 * Renderiza a sidebar de navegação (links âncora para páginas PHP).
 *
 * @var string $active
 * @var string $title
 * @var string $subtitle
 */
$nav = [
    ['id' => 'dashboard', 'label' => 'Painel', 'href' => '/index.php', 'icon' => 'chart'],
    ['id' => 'transactions', 'label' => 'Transações', 'href' => '/transactions.php', 'icon' => 'swap'],
    ['id' => 'accounts', 'label' => 'Contas', 'href' => '/accounts.php', 'icon' => 'wallet'],
    ['id' => 'categories', 'label' => 'Categorias', 'href' => '/categories.php', 'icon' => 'tags'],
    ['id' => 'payees', 'label' => 'Beneficiários', 'href' => '/payees.php', 'icon' => 'users'],
    ['id' => 'institutions', 'label' => 'Instituições', 'href' => '/institutions.php', 'icon' => 'building'],
];
$active ??= 'dashboard';
$title ??= 'MoneyApp';
$subtitle ??= '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · MoneyApp</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body data-page="<?= htmlspecialchars($active) ?>">
    <div class="app">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-logo">M</div>
                <div>
                    <div class="brand-name">MoneyApp</div>
                    <div class="brand-sub">v2.0 · pt-BR · BRL</div>
                </div>
            </div>
            <nav class="nav">
                <?php foreach ($nav as $item): ?>
                    <a href="<?= $item['href'] ?>" class="nav-item <?= $active === $item['id'] ? 'active' : '' ?>">
                        <span class="icon icon-<?= $item['icon'] ?>"></span>
                        <span class="nav-label"><?= $item['label'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer">W&W Tecnologia</div>
        </aside>
        <main class="content">
            <header class="page-header">
                <div>
                    <h1><?= htmlspecialchars($title) ?></h1>
                    <?php if ($subtitle): ?><p class="page-subtitle"><?= htmlspecialchars($subtitle) ?></p><?php endif; ?>
                </div>
                <div class="page-actions" id="page-actions"></div>
            </header>
            <div id="page-content" class="page-content"></div>
        </main>
    </div>
    <div id="modal-root"></div>
    <div id="toast-root"></div>
    <script src="/assets/components.js"></script>
    <script src="/assets/app.js"></script>
