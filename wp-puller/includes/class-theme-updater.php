<?php

/**
 * Theme Updater class for WP Puller.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP_Puller_Theme_Updater Class.
 */
class WP_Puller_Theme_Updater
{

    /**
     * GitHub API instance.
     *
     * @var WP_Puller_GitHub_API
     */
    private $github_api;

    /**
     * Backup instance.
     *
     * @var WP_Puller_Backup
     */
    private $backup;

    /**
     * Logger instance.
     *
     * @var WP_Puller_Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param WP_Puller_GitHub_API $github_api GitHub API instance.
     * @param WP_Puller_Backup     $backup     Backup instance.
     * @param WP_Puller_Logger     $logger     Logger instance.
     */
    public function __construct($github_api, $backup, $logger)
    {
        $this->github_api = $github_api;
        $this->backup     = $backup;
        $this->logger     = $logger;
    }

    /**
     * Update the theme from GitHub.
     *
     * @param string $source Update source (webhook, manual).
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function update($source = 'manual')
    {
        $repo_url = get_option('wp_puller_repo_url', '');
        $branch   = get_option('wp_puller_branch', 'main');

        if (empty($repo_url)) {
            $error = new WP_Error(
                'no_repo',
                __('No GitHub repository configured.', 'wp-puller')
            );
            $this->logger->log_update_error($error->get_error_message(), $source);
            return $error;
        }

        $parsed = $this->github_api->parse_repo_url($repo_url);

        if (! $parsed) {
            $error = new WP_Error(
                'invalid_repo',
                __('Invalid GitHub repository URL.', 'wp-puller')
            );
            $this->logger->log_update_error($error->get_error_message(), $source);
            return $error;
        }

        $latest_commit = $this->github_api->get_latest_commit($parsed['owner'], $parsed['repo'], $branch);

        if (is_wp_error($latest_commit)) {
            $this->logger->log_update_error($latest_commit->get_error_message(), $source);
            return $latest_commit;
        }

        $backup_path = $this->backup->create_backup();

        if (is_wp_error($backup_path)) {
            $this->logger->log_update_error($backup_path->get_error_message(), $source);
            return $backup_path;
        }

        $this->logger->log_backup_created($backup_path);

        $zip_file = $this->github_api->download_archive($parsed['owner'], $parsed['repo'], $branch);

        if (is_wp_error($zip_file)) {
            $this->logger->log_update_error($zip_file->get_error_message(), $source);
            return $zip_file;
        }

        $result = $this->install_theme($zip_file, $parsed['repo'], $branch);

        @unlink($zip_file);

        if (is_wp_error($result)) {
            $this->logger->log_update_error($result->get_error_message(), $source);
            return $result;
        }

        update_option('wp_puller_latest_commit', $latest_commit['sha']);
        update_option('wp_puller_last_check', time());

        $this->logger->log_update_success($latest_commit['short_sha'], $source, array(
            'commit_sha'     => $latest_commit['sha'],
            'commit_message' => substr($latest_commit['message'], 0, 100),
        ));

        do_action('wp_puller_theme_updated', $latest_commit, $source);

        return true;
    }

    /**
     * Check if an update is available.
     *
     * @return array|WP_Error Array with update info, or WP_Error on failure.
     */
    public function check_for_updates()
    {
        $repo_url = get_option('wp_puller_repo_url', '');
        $branch   = get_option('wp_puller_branch', 'main');

        if (empty($repo_url)) {
            return new WP_Error(
                'no_repo',
                __('No GitHub repository configured.', 'wp-puller')
            );
        }

        $parsed = $this->github_api->parse_repo_url($repo_url);

        if (! $parsed) {
            return new WP_Error(
                'invalid_repo',
                __('Invalid GitHub repository URL.', 'wp-puller')
            );
        }

        $this->github_api->clear_cache();

        $latest_commit = $this->github_api->get_latest_commit($parsed['owner'], $parsed['repo'], $branch);

        if (is_wp_error($latest_commit)) {
            return $latest_commit;
        }

        $current_commit = get_option('wp_puller_latest_commit', '');

        update_option('wp_puller_last_check', time());

        return array(
            'update_available' => ! empty($current_commit) && $current_commit !== $latest_commit['sha'],
            'current_commit'   => $current_commit,
            'latest_commit'    => $latest_commit,
            'is_new_setup'     => empty($current_commit),
        );
    }

    /**
     * Install theme from ZIP file.
     *
     * @param string $zip_file ZIP file path.
     * @param string $repo     Repository name.
     * @param string $branch   Branch name.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    private function install_theme($zip_file, $repo, $branch)
    {
        global $wp_filesystem;

        if (! $wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if (! $wp_filesystem) {
            return new WP_Error(
                'filesystem_unavailable',
                __('Could not initialize the WordPress filesystem API.', 'wp-puller')
            );
        }

        $theme = wp_get_theme();

        $temp_dir = get_temp_dir() . 'wp-puller-' . uniqid();

        $result = unzip_file($zip_file, $temp_dir);

        if (is_wp_error($result)) {
            $wp_filesystem->delete($temp_dir, true);
            return new WP_Error(
                'unzip_failed',
                __('Failed to extract theme archive.', 'wp-puller')
            );
        }

        $extracted_dir = $temp_dir . '/' . $repo . '-' . $branch;

        if (! is_dir($extracted_dir)) {
            $dirs = glob($temp_dir . '/*', GLOB_ONLYDIR);

            if (! empty($dirs)) {
                $extracted_dir = $dirs[0];
            } else {
                $wp_filesystem->delete($temp_dir, true);
                return new WP_Error(
                    'invalid_archive',
                    __('Invalid theme archive structure.', 'wp-puller')
                );
            }
        }

        $install_targets = $this->build_install_targets($theme, $extracted_dir);

        if (is_wp_error($install_targets)) {
            $wp_filesystem->delete($temp_dir, true);
            return $install_targets;
        }

        foreach ($install_targets as $target) {
            $validation_result = $this->validate_theme_source($target['source_dir'], $target['path']);

            if (is_wp_error($validation_result)) {
                $wp_filesystem->delete($temp_dir, true);
                return $validation_result;
            }
        }

        foreach ($install_targets as $target) {
            $this->clear_theme_directory($target['target_dir']);

            $copy_result = copy_dir($target['source_dir'], $target['target_dir']);

            if (is_wp_error($copy_result)) {
                $wp_filesystem->delete($temp_dir, true);

                return new WP_Error(
                    'copy_failed',
                    sprintf(
                        /* translators: %s: theme slug */
                        __('Failed to copy files for theme "%s".', 'wp-puller'),
                        $target['theme_slug']
                    )
                );
            }
        }

        $wp_filesystem->delete($temp_dir, true);

        $this->clear_theme_cache();

        return true;
    }

    /**
     * Build a list of install targets based on configured paths.
     *
     * @param WP_Theme $active_theme  Active theme object.
     * @param string   $extracted_root Extracted repository root.
     * @return array|WP_Error
     */
    private function build_install_targets($active_theme, $extracted_root)
    {
        $primary_path   = trim((string) get_option('wp_puller_theme_path', ''), '/');
        $secondary_path = trim((string) get_option('wp_puller_theme_path_secondary', ''), '/');

        $targets = array(
            array(
                'path'       => $primary_path,
                'source_dir' => $this->build_source_dir($extracted_root, $primary_path),
                'target_dir' => $active_theme->get_stylesheet_directory(),
                'theme_slug' => $active_theme->get_stylesheet(),
            ),
        );

        if (! empty($secondary_path) && $secondary_path !== $primary_path) {
            $parent_theme = $active_theme->parent();

            if ($parent_theme) {
                $target_dir = $parent_theme->get_stylesheet_directory();
                $theme_slug = $parent_theme->get_stylesheet();
            } else {
                $theme_slug = sanitize_file_name(basename($secondary_path));
                $target_dir = trailingslashit(get_theme_root()) . $theme_slug;

                if (! is_dir($target_dir)) {
                    return new WP_Error(
                        'secondary_target_not_found',
                        sprintf(
                            /* translators: %s: theme slug */
                            __('Secondary theme target "%s" was not found. Activate a child theme with a parent or install the target theme first.', 'wp-puller'),
                            $theme_slug
                        )
                    );
                }
            }

            $targets[] = array(
                'path'       => $secondary_path,
                'source_dir' => $this->build_source_dir($extracted_root, $secondary_path),
                'target_dir' => $target_dir,
                'theme_slug' => $theme_slug,
            );
        }

        return $targets;
    }

    /**
     * Build a source directory path from extracted root and configured theme path.
     *
     * @param string $extracted_root Extracted repository root.
     * @param string $theme_path     Relative path in repository.
     * @return string
     */
    private function build_source_dir($extracted_root, $theme_path)
    {
        if (empty($theme_path)) {
            return $extracted_root;
        }

        return $extracted_root . '/' . $theme_path;
    }

    /**
     * Validate that a source path contains a valid WordPress theme.
     *
     * @param string $source_dir Source directory.
     * @param string $theme_path Configured theme path.
     * @return true|WP_Error
     */
    private function validate_theme_source($source_dir, $theme_path)
    {
        if (! is_dir($source_dir)) {
            return new WP_Error(
                'path_not_found',
                sprintf(
                    /* translators: %s: theme path */
                    __('Theme path "%s" not found in repository.', 'wp-puller'),
                    $theme_path
                )
            );
        }

        $style_css = $source_dir . '/style.css';

        if (! file_exists($style_css)) {
            $hint = '';
            if (empty($theme_path)) {
                $subdirs = glob($source_dir . '/*', GLOB_ONLYDIR);
                foreach ((array) $subdirs as $subdir) {
                    if (file_exists($subdir . '/style.css')) {
                        $hint = sprintf(
                            /* translators: %s: directory name */
                            __(' Found theme in "%s" - set this as Theme Path in settings.', 'wp-puller'),
                            basename($subdir)
                        );
                        break;
                    }
                }
            }

            return new WP_Error(
                'not_a_theme',
                __('The repository does not contain a valid WordPress theme (missing style.css).', 'wp-puller') . $hint
            );
        }

        $theme_data = get_file_data($style_css, array('Name' => 'Theme Name'));

        if (empty($theme_data['Name'])) {
            return new WP_Error(
                'invalid_theme',
                __('The style.css file does not contain a valid Theme Name header.', 'wp-puller')
            );
        }

        return true;
    }

    /**
     * Clear theme directory contents.
     *
     * @param string $dir Directory path.
     */
    private function clear_theme_directory($dir)
    {
        global $wp_filesystem;

        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $wp_filesystem->delete($path, true);
            } else {
                $wp_filesystem->delete($path);
            }
        }
    }

    /**
     * Clear theme-related caches.
     */
    private function clear_theme_cache()
    {
        wp_clean_themes_cache();

        delete_transient('dirsize_cache');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        do_action('wp_puller_cache_cleared');
    }

    /**
     * Get the current theme info.
     *
     * @return array
     */
    public function get_current_theme_info()
    {
        $theme = wp_get_theme();

        return array(
            'name'       => $theme->get('Name'),
            'version'    => $theme->get('Version'),
            'author'     => $theme->get('Author'),
            'stylesheet' => $theme->get_stylesheet(),
            'directory'  => $theme->get_stylesheet_directory(),
        );
    }

    /**
     * Get update status.
     *
     * @return array
     */
    public function get_status()
    {
        $repo_url             = get_option('wp_puller_repo_url', '');
        $branch               = get_option('wp_puller_branch', 'main');
        $theme_path           = get_option('wp_puller_theme_path', '');
        $theme_path_secondary = get_option('wp_puller_theme_path_secondary', '');
        $current_commit       = get_option('wp_puller_latest_commit', '');
        $last_check           = get_option('wp_puller_last_check', 0);
        $auto_update          = get_option('wp_puller_auto_update', true);

        $parsed = $this->github_api->parse_repo_url($repo_url);

        return array(
            'is_configured'  => ! empty($repo_url) && false !== $parsed,
            'repo_url'       => $repo_url,
            'branch'         => $branch,
            'theme_path'     => $theme_path,
            'theme_path_secondary' => $theme_path_secondary,
            'current_commit' => $current_commit,
            'short_commit'   => ! empty($current_commit) ? substr($current_commit, 0, 7) : '',
            'last_check'     => $last_check,
            'auto_update'    => $auto_update,
            'repo_owner'     => $parsed ? $parsed['owner'] : '',
            'repo_name'      => $parsed ? $parsed['repo'] : '',
        );
    }
}
