<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The suite migrates the REAL tenant-template into a throwaway database and
 * drives the REAL database adapter against it. A fake DatabasePort would hide
 * the two things most worth testing: whether the SQL is valid on the driver in
 * front of it, and whether the columns the code names actually exist.
 *
 * There is no `composer install` step here on purpose. The plugins in this set
 * reference each other both ways — the sync engine's catalogue test reads every
 * domain plugin's schema, and every domain plugin writes through the sync
 * engine — so resolving them as mutual Composer packages means reading a
 * composer.json off every branch and tag of every repository, for every plugin,
 * to arrive at directories that are already sitting on this disk. They are
 * located directly instead.
 *
 * A plugin is looked for in three places, in order:
 *
 *   1. beside this one as a PROJECT plugin         <parent>/<Folder>
 *   2. beside this one as a CHECKOUT               <parent>/hkm-plugin-<slug>
 *   3. in the plugin store, newest version first   ~/.cache/hkm/plugin-store/<Folder>/*
 *
 * so the same file works from a bare clone, from a project's plugins/ directory
 * and from an installed release, which is three layouts no fixed relative path
 * survives.
 */
$kernel = getenv('HKM_KERNEL_PATH') ?: '/Users/user/Documents/HKMCODE/PHP';

if (!is_file($kernel . '/vendor/autoload.php')) {
    fwrite(STDERR, "The kernel was not found at {$kernel}.\nSet HKM_KERNEL_PATH to a built kernel checkout.\n");
    exit(1);
}

require_once $kernel . '/vendor/autoload.php';

if (is_file($kernel . '/src/Kernel/Support/helpers.php')) {
    require_once $kernel . '/src/Kernel/Support/helpers.php';
}

$self   = dirname(__DIR__);          // this plugin's root
$beside = dirname($self);            // the project's plugins/ dir, or the checkouts dir

/** Where a plugin folder lives, whichever of the three layouts this is. */
$locate = static function (string $folder) use ($beside): ?string {
    // LoanSync -> loan-sync, UploadGuard -> upload-guard, Database -> database
    $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $folder));

    foreach ([$beside . '/' . $folder, $beside . '/hkm-plugin-' . $slug] as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }

    $versions = glob((string) getenv('HOME') . '/.cache/hkm/plugin-store/' . $folder . '/*', GLOB_ONLYDIR) ?: [];

    if ($versions !== []) {
        // Newest mtime wins: the store keeps every version ever installed.
        usort($versions, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $versions[0];
    }

    return null;
};

$map = [
    'Tests\\Unit\\Plugins\\LoanSync\\' => __DIR__ . '/',
    'Plugins\\LoanSync\\'              => $self . '/',
];

$missing = [];

foreach (['Database', 'Storage', 'UploadGuard'] as $folder) {
    $dir = $locate($folder);

    if ($dir === null) {
        $missing[] = $folder;
        continue;
    }

    $map['Plugins\\' . $folder . '\\'] = $dir . '/';
}

if ($missing !== []) {
    fwrite(STDERR, sprintf(
        "This suite needs these plugins checked out beside it, or installed:\n  %s\n",
        implode("\n  ", $missing),
    ));
    exit(1);
}

// Optional siblings. Absent, the suite still runs and says what it could not
// cover — see TestDatabase::available().
foreach (['LoanCustomers', 'LoanOrganisation', 'LoanLoans', 'LoanRepayments', 'LoanCash', 'LoanExpenses', 'LoanField'] as $folder) {
    $dir = $locate($folder);

    if ($dir !== null) {
        $map['Plugins\\' . $folder . '\\'] = $dir . '/';
    }
}

spl_autoload_register(static function (string $class) use ($map): void {
    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});
