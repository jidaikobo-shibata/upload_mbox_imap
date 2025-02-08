<?php
// ussage
// php -d memory_limit=512M upload_mbox_imap.php

// IMAP接続情報
$imap_host = '{imap.example.com:993/imap/ssl}';
$username = 'example@example.com';
$password = 'PASSWD';
$local_folder_path = '/home/USERNAME/snap/thunderbird/common/.thunderbird/EXAMPLE.default/Mail/Local Folders/EXAMPLE.sbd';
$imap_root_folder = 'INBOX';

// IMAPサーバーに接続
$mailbox = imap_open($imap_host, $username, $password) or die('Cannot connect to IMAP server: ' . imap_last_error());

// ローカルフォルダ自身をIMAP上に作成
$base_folder_name = basename($local_folder_path, '.sbd');
$imap_base_folder = "$imap_root_folder.$base_folder_name";

if (!folder_exists($mailbox, $imap_host, $imap_base_folder)) {
    create_folder($mailbox, $imap_host, $imap_base_folder);
    echo "Base folder '$imap_base_folder' created.\n";
}

// 再帰的にローカルフォルダを走査して処理
process_local_folder($mailbox, $imap_host, $local_folder_path, $imap_base_folder);

// IMAP接続を閉じる
imap_close($mailbox);

/**
 * ローカルフォルダを再帰的に処理する関数
 */
function process_local_folder($mailbox, $imap_host, $local_path, $imap_folder) {
    $entries = scandir($local_path);
    // echo "Processing local folder: $local_path\n";  // ログ追加

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full_path = "$local_path/$entry";
        echo "Found entry: $entry (full path: $full_path)\n";  // ログ追加

        // mboxファイル（フォルダ名として扱う）を処理
        if (is_file($full_path) && !ends_with($entry, '.msf')) {
            echo "Detected mbox file: $full_path\n";  // ログ追加
            $mbox_folder_name = "$imap_folder." . pathinfo($entry, PATHINFO_FILENAME);  // ファイル名のみ取得
            echo "Target IMAP folder for mbox: $mbox_folder_name\n";  // ログ追加

            if (!folder_exists($mailbox, $imap_host, $mbox_folder_name)) {
                echo "Creating folder: $mbox_folder_name\n";  // ログ追加
                create_folder($mailbox, $imap_host, $mbox_folder_name);
                echo "Folder '$mbox_folder_name' created.\n";
            }
            upload_mbox_file($mailbox, $imap_host, $full_path, $mbox_folder_name);
        }

        // サブディレクトリ（.sbd）を再帰的に処理
        elseif (is_dir($full_path) && ends_with($entry, '.sbd')) {
            echo "Detected subdirectory: $full_path\n";  // ログ追加
            $imap_subfolder = "$imap_folder." . basename($entry, '.sbd');
            if (!folder_exists($mailbox, $imap_host, $imap_subfolder)) {
                create_folder($mailbox, $imap_host, $imap_subfolder);
                echo "Subfolder '$imap_subfolder' created.\n";
            }
            process_local_folder($mailbox, $imap_host, $full_path, $imap_subfolder);
        }
    }
}

/**
 * mboxファイルをアップロードする関数
 */
function upload_mbox_file($mailbox, $imap_host, $mbox_path, $imap_folder) {
    echo "Uploading mbox file '$mbox_path' to '$imap_folder'...\n";

    $mbox_handle = fopen($mbox_path, 'r');
    if (!$mbox_handle) {
        echo "Failed to open mbox file '$mbox_path'.\n";
        return;
    }

    $current_mail = '';
    while (($line = fgets($mbox_handle)) !== false) {
        if (strpos($line, 'From ') === 0 && $current_mail !== '') {
            upload_mail($mailbox, $imap_host, $current_mail, $imap_folder);
            $current_mail = '';
        }
        $current_mail .= $line;
    }

    // 最後のメールをアップロード
    if (!empty($current_mail)) {
        upload_mail($mailbox, $imap_host, $current_mail, $imap_folder);
    }

    fclose($mbox_handle);
}

/**
 * メールをIMAPサーバーにアップロードする関数
 * きほん、既読でアップする
 */
function upload_mail($mailbox, $imap_host, $mail_content, $folder_name) {
    $encoded_folder_name = encode_modified_utf7("$imap_host$folder_name");
    $result = imap_append($mailbox, $encoded_folder_name, $mail_content, "\\Seen");
    if ($result) {
        echo "Mail uploaded successfully to '$folder_name'.\n";
    } else {
        echo "Failed to upload mail to '$folder_name': " . imap_last_error() . "\n";
    }
}

/**
 * フォルダが存在するか確認する関数
 */
function folder_exists($mailbox, $imap_host, $folder_name) {
    $encoded_folder_name = encode_modified_utf7("$imap_host$folder_name");
    $folders = imap_list($mailbox, $imap_host, '*');

    if ($folders) {
        foreach ($folders as $folder) {
            if ($folder === $encoded_folder_name) {
                return true;
            }
        }
    }
    return false;
}

/**
 * フォルダを作成する関数
 */
function create_folder($mailbox, $imap_host, $folder_name) {
    // echo "Attempting to create IMAP folder: $folder_name\n";  // ログ追加
    $encoded_folder_name = encode_modified_utf7("$imap_host$folder_name");
    $result = imap_createmailbox($mailbox, $encoded_folder_name);
    if ($result) {
        echo "Successfully created folder: $folder_name\n";
    } else {
        echo "Failed to create folder '$folder_name': " . imap_last_error() . "\n";
    }
    return $result;
}

/**
 * Modified UTF-7 エンコード関数
 */
function encode_modified_utf7($str) {
    // echo "Encoding folder name: $str\n";  // ログ追加
    $encoded = preg_replace_callback('/[^\x20-\x7E]+/', function ($matches) {
        $utf16 = mb_convert_encoding($matches[0], 'UTF-16BE', 'UTF-8');
        $base64 = base64_encode($utf16);
        $modified_utf7 = str_replace('/', ',', $base64);
        return '&' . rtrim($modified_utf7, '=') . '-';
    }, $str);
    // echo "Encoded folder name: $encoded\n";  // ログ追加
    return $encoded;
}

/**
 * 文字列の末尾を確認する関数
 */
function ends_with($haystack, $needle) {
    return substr($haystack, -strlen($needle)) === $needle;
}
