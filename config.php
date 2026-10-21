<?php

declare(strict_types=1);

const APP_NAME = 'PI Project Management';
const APP_VERSION = '5.0.0';
const SESSION_LIFETIME = 30 * 24 * 60 * 60;

function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    if (strtolower($value) === 'true') {
        return true;
    }
    if (strtolower($value) === 'false') {
        return false;
    }

    return $value;
}

const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'project_tracker';
const DB_USER = 'root';
const DB_PASS = '';

const SESSION_COOKIE = 'pt_session';
const SESSION_SECRET = 'project-tracker-php-secret-key';
const APP_ROOT = __DIR__;
const STORAGE_DIR = APP_ROOT . '/storage';
const FILES_DIR = STORAGE_DIR . '/files';
const BACKUP_DIR = STORAGE_DIR . '/backup';
