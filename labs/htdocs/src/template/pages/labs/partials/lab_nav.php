<?php
$currentUri = $_SERVER['REQUEST_URI'];
$hash = Session::get('full_instance_hash');

// Define tabs
$tabs = [
    'dashboard' => [
        'label' => 'Dashboard',
        'url' => "/labs/dashboard/$hash",
        'matcher' => '/labs/dashboard/'
    ],
    'domains' => [
        'label' => 'Domains',
        'url' => "/labs/domains/$hash",
        'matcher' => '/labs/domains/'
    ],
    'preferences' => [
        'label' => 'Preferences',
        'url' => "/labs/preferences/$hash",
        'matcher' => '/labs/preferences/'
    ],
    'activity' => [
        'label' => 'Activity',
        'url' => "/labs/activity/$hash",
        'matcher' => '/labs/activity/'
    ]
];
?>

<div class="row m-0 p-0">
    <ul class="nav nav-tabs lab-nav-tabs border-0">
        <?php foreach($tabs as $key => $tab): 
            $isActive = (strpos($currentUri, $tab['matcher']) !== false);
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $isActive ? 'active' : '' ?>" 
               href="<?= $tab['url'] ?>">
                <?= $tab['label'] ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
