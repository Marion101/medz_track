<?php
declare(strict_types=1);

function get_theme_preference(): string
{
    $theme = $_SESSION['theme'] ?? 'auto';
    return in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';
}

function theme_body_class(string $baseClass = ''): string
{
    $classes = [];
    if ($baseClass !== '') {
        $classes[] = $baseClass;
    }

    $theme = get_theme_preference();
    if ($theme === 'dark') {
        $classes[] = 'dark-theme';
    } elseif ($theme === 'light') {
        $classes[] = 'light-theme';
    }

    return implode(' ', $classes);
}

function theme_switch_html(string $redirect, string $className = 'theme-switcher'): string
{
    $current = get_theme_preference();
    $choices = [
        'light' => 'Light',
        'dark' => 'Dark',
        'auto' => 'Auto',
    ];

    $html = '<form action="theme.php" method="post" class="' . htmlspecialchars($className, ENT_QUOTES) . '">';
    $html .= '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirect, ENT_QUOTES) . '">';

    foreach ($choices as $value => $label) {
        $active = $current === $value ? ' active' : '';
        $html .= '<button type="submit" name="theme" value="' . htmlspecialchars($value, ENT_QUOTES) . '" class="theme-option' . $active . '">' . htmlspecialchars($label) . '</button>';
    }

    $html .= '</form>';

    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $theme = $_POST['theme'] ?? 'auto';
    $_SESSION['theme'] = in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';

    $redirect = (string) ($_POST['redirect'] ?? 'dashboard.php');
    if ($redirect === '' || preg_match('/^https?:/i', $redirect)) {
        $redirect = 'dashboard.php';
    }

    header('Location: ' . $redirect);
    exit;
}
