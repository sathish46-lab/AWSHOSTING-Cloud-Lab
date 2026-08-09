<?php
Session::addMetaTag('<title>Services - Tom Labs</title>');
Session::addCustomJs('/assets/js/copy.js');

$services = [
    [
        "title" => "MySQL Server",
        "img" => "https://www.mysql.com/common/logos/logo-mysql-170x115.png",
        "host" => "mysql.tomweb.in",
        "desc" => "MySQL is the world's most popular open source database.",
        "manage" => "/services/mysql",
        "learn" => "https://dev.mysql.com/doc/",
        "ports" => [
            "MySQL Database Service" => "3306"
        ],
        "pf" => [
            "VS Code Desktop" => "mysql.tomweb.in:3306"
        ]
    ],
    [
        "title" => "Adminer",
        "badge" => "v4.8.1",
        "img" => "https://upload.wikimedia.org/wikipedia/commons/4/41/Adminer_logo.png",
        "host" => "adminer.tomweb.in",
        "desc" => "Adminer (formerly phpMinAdmin) is a full-featured database management tool written in PHP. Supports MySQL, PostgreSQL, SQLite, MS SQL, Oracle and more.",
        "manage" => "/services/adminer",
        "learn" => "https://www.adminer.org/",
        "ports" => [
            "Database Adminer (HTTP)" => "8080"
        ],
        "pf" => [
            "VS Code Desktop" => "adminer.tomweb.in:8080",
            "VS Code Web ($)" => "socat TCP-LISTEN:8080,reuseaddr,fork TCP:adminer.tomweb.in:8080"
        ]
    ],
    [
        "title" => "MariaDB Server",
        "img" => "https://cdn.jsdelivr.net/gh/devicons/devicon@latest/icons/mariadb/mariadb-original-wordmark.svg",
        "host" => "mariadb.tomweb.in",
        "desc" => "MariaDB Server is one of the most popular open source relational databases.",
        "manage" => "/services/mariadb",
        "learn" => "https://mariadb.com/kb/en/",
        "ports" => [
            "MariaDB Database Service" => "3306"
        ],
        "pf" => [
            "VS Code Desktop" => "mariadb.tomweb.in:3306"
        ]
    ],
    [
        "title" => "PostgreSQL Server",
        "img" => "https://wiki.postgresql.org/images/a/a4/PostgreSQL_logo.3colors.svg",
        "host" => "postgresql.tomweb.in",
        "desc" => "PostgreSQL is a powerful, open source object-relational database system.",
        "manage" => "/services/postgresql",
        "learn" => "https://www.postgresql.org/docs/",
        "ports" => [
            "PostgreSQL Database Service" => "5432"
        ],
        "pf" => [
            "VS Code Desktop" => "postgresql.tomweb.in:5432"
        ]
    ],
    [
        "title" => "MongoDB Server",
        "img" => "https://cdn.jsdelivr.net/gh/devicons/devicon@latest/icons/mongodb/mongodb-original-wordmark.svg",
        "host" => "mongodb.tomweb.in",
        "desc" => "MongoDB is a source-available cross-platform document-oriented database program.",
        "manage" => "/services/mongodb",
        "learn" => "https://www.mongodb.com/docs/",
        "ports" => [
            "MongoDB Database Service" => "27017"
        ],
        "pf" => [
            "VS Code Desktop" => "mongodb.tomweb.in:27017"
        ]
    ],
    [
        "title" => "RabbitMQ Server",
        "img" => "https://upload.wikimedia.org/wikipedia/commons/7/71/RabbitMQ_logo.svg",
        "host" => "rabbitmq.tomweb.in",
        "desc" => "RabbitMQ is the most widely deployed open source message broker. RabbitMQ is used worldwide at small startups and large enterprises.",
        "manage" => "/services/rabbitmq",
        "learn" => "https://www.rabbitmq.com/documentation.html",
        "ports" => [
            "Management Interface (HTTP)" => "15672",
            "Advanced Message Queueing Protocol" => "5672",
            "Prometheus Monitoring Interface (HTTP)" => "15692",
            "WebStomp Service" => "15674",
            "Stomp Service" => "61613",
            "MQTT Service" => "1883"
        ],
        "pf" => [
            "VS Code Desktop" => "rabbitmq.tomweb.in:15672",
            "VS Code Web ($)" => "socat TCP-LISTEN:15672,reuseaddr,fork TCP:rabbitmq.tomweb.in:15672"
        ]
    ],
    [
        "title" => "Redis Server",
        "img" => "https://cdn.jsdelivr.net/gh/devicons/devicon@latest/icons/redis/redis-original-wordmark.svg",
        "host" => "redis.tomweb.in",
        "desc" => "Redis is an open source (BSD), in-memory data structure store, used as a database, cache, and message broker.",
        "manage" => "/services/redis",
        "learn" => "https://redis.io/documentation",
        "ports" => [
            "Redis Server" => "6379"
        ],
        "pf" => [
            "VS Code Desktop" => "redis.tomweb.in:6379"
        ]
    ],
    [
        "title" => "Memcached Datastore",
        "img" => "https://upload.wikimedia.org/wikipedia/en/thumb/2/27/Memcached.svg/1200px-Memcached.svg.png",
        "host" => "memcached.tomweb.in",
        "desc" => "Memcached is a general-purpose distributed memory caching system.",
        "manage" => "/services/memcached",
        "learn" => "https://memcached.org/about",
        "ports" => [
            "Memcached Service" => "11211"
        ],
        "pf" => [
            "VS Code Desktop" => "memcached.tomweb.in:11211"
        ]
    ]
];
?>

<div class="blur banner mb-3 rounded-0 border-bottom border-secondary border-opacity-10">
    <div class="container-fluid px-4">
        <div class="row align-items-center py-3">
            <div class="col">
                <h1 class="fw-bold theme-text m-0" style="font-size: 1.8rem; letter-spacing: -0.5px;">Services</h1>
                <p class="text-secondary opacity-75 mt-2 mb-0" style="font-size: 0.85rem; line-height: 1.7; letter-spacing: 0.2px; max-width: 900px;">
                    List of available services are shown here. You can utilize these services inside your laboratory. Some services require authentication and other configuration which can be managed from here. Services are still in beta and we are constantly upgrading them.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-3">
    <div class="row row-cols-1 row-cols-md-3 g-4 pb-4 align-items-start" id="masonry-area" data-masonry='{"percentPosition": true }'>

    <?php foreach ($services as $i => $svc): ?>
    <div class="col card-entrance">
        <div class="card glass-lab-card h-100 border-0 shadow-lg rounded-4 blur" style="--card-accent: var(--accent-muted);">
            <div class="glass-lab-card-glow"></div>
            <div class="glass-lab-card-body d-flex flex-column h-100 p-4 position-relative" style="z-index: 2;">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="glass-lab-avatar">
                        <?php if($svc['title'] == 'Adminer'): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#2196F3" style="width: 28px; height: 28px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                        <?php else: ?>
                            <img src="<?= htmlspecialchars($svc['img']) ?>" style="width: 28px; height: 28px; object-fit: contain;">
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h5 class="glass-lab-title fw-bold mb-0 text-truncate">
                            <?= htmlspecialchars($svc['title']) ?>
                        </h5>
                        <div class="glass-lab-subtitle d-flex align-items-center gap-2 mt-1">
                            <span class="glass-badge"><?= htmlspecialchars($svc['host']) ?></span>
                        </div>
                    </div>
                </div>

                <p class="text-secondary mb-3" style="font-size: 0.85rem; line-height: 1.5; opacity: 0.8;"><?= htmlspecialchars($svc['desc']) ?></p>

                <!-- Ports Section -->
                <div class="bg-dark bg-opacity-50 rounded p-2 px-3 mb-3 border border-light border-opacity-10">
                    <?php
                    $ports_array = array_merge(["Hostname" => $svc['host']], $svc['ports']);
                    $port_count = count($ports_array);
                    $p_idx = 0;
                    foreach ($ports_array as $name => $val):
                        $p_idx++;
                        $is_last = ($p_idx === $port_count);
                    ?>
                    <div class="d-flex justify-content-between align-items-center <?= $is_last ? '' : 'mb-2 pb-2 border-bottom border-light border-opacity-10' ?>">
                        <span class="text-secondary fw-semibold pe-2" style="font-size: 0.8rem;"><?= htmlspecialchars($name) ?></span>
                        <div class="d-flex align-items-center text-end" style="max-width: 65%;">
                            <span class="text-light font-monospace me-2 text-break" style="font-size: 0.8rem;"><?= htmlspecialchars($val) ?></span>
                            <i class='bx bx-copy text-secondary cursor-pointer hover-white' data-copy="<?= htmlspecialchars($val) ?>"></i>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Port Forwarding Section -->
                <?php if (!empty($svc['pf'])): ?>
                <h6 class="text-white fw-bold mb-2" style="font-size: 0.8rem;">Port Forwarding</h6>
                <div class="bg-dark bg-opacity-50 rounded p-2 px-3 border border-light border-opacity-10">
                    <?php
                    $pf_count = count($svc['pf']);
                    $pf_idx = 0;
                    foreach ($svc['pf'] as $name => $val):
                        $pf_idx++;
                        $is_last = ($pf_idx === $pf_count);
                    ?>
                    <div class="d-flex justify-content-between align-items-center <?= $is_last ? '' : 'mb-2 pb-2 border-bottom border-light border-opacity-10' ?>">
                        <span class="text-secondary fw-semibold pe-2" style="font-size: 0.8rem;"><?= htmlspecialchars($name) ?></span>
                        <div class="d-flex align-items-center text-end" style="max-width: 65%;">
                            <span class="text-light font-monospace me-2 text-break" style="font-size: 0.8rem;"><?= htmlspecialchars($val) ?></span>
                            <i class='bx bx-copy text-secondary cursor-pointer hover-white' data-copy="<?= htmlspecialchars($val) ?>"></i>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="mt-auto d-flex gap-2 pt-3">
                    <a href="<?= htmlspecialchars($svc['manage']) ?>" class="glass-btn glass-btn-primary flex-grow-1">
                        <i class='bx bx-cog'></i> Manage
                    </a>
                    <a href="<?= htmlspecialchars($svc['learn']) ?>" target="_blank" class="glass-btn glass-btn-success flex-grow-1">
                        <i class='bx bx-book-open'></i> Learn More
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    </div>
</div>

<style>
.cursor-pointer { cursor: pointer; }
.hover-white { transition: color 0.2s; }
.hover-white:hover { color: #ffffff !important; }
</style>
<script>
    // Initialize Masonry manually like domains.php
    if (typeof window.onPageLoad === 'function') {
        window.onPageLoad(function() {
            var grid = document.querySelector('#masonry-area');
            if (grid && typeof Masonry !== 'undefined') {
                new Masonry(grid, {
                    percentPosition: true
                });
            }
        });
    } else {
        document.addEventListener("DOMContentLoaded", function() {
            var grid = document.querySelector('#masonry-area');
            if (grid && typeof Masonry !== 'undefined') {
                new Masonry(grid, {
                    percentPosition: true
                });
            }
        });
    }
</script>
