<?php

declare(strict_types=1);

namespace Migrator\Admin;

use Migrator\Contract\HasHooks;
use Migrator\Engine\Export\ExportPipeline;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

/**
 * AJAX endpoints driving the resumable browser export, plus an authenticated
 * download handler. Every endpoint verifies the nonce and the manage_options
 * capability before doing anything, and the download is confined to the
 * workspace so no arbitrary file can be read.
 */
final class Ajax implements HasHooks
{
    public function __construct(
        private ExportPipeline $export,
        private Workspace $workspace,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('wp_ajax_migrator_export_start', [$this, 'exportStart']);
        add_action('wp_ajax_migrator_export_step', [$this, 'exportStep']);
        add_action('wp_ajax_migrator_download', [$this, 'download']);
    }

    public function exportStart(): void
    {
        $this->guard();
        try {
            $this->export->clear();
            wp_send_json_success($this->shape($this->export->start()));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function exportStep(): void
    {
        $this->guard();
        try {
            wp_send_json_success($this->shape($this->export->step()));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Stream the finished archive as a download. Authenticated, capability-checked,
     * and restricted to a file inside the workspace.
     */
    public function download(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('migrator_download', 'nonce')) {
            wp_die(esc_html__('Not allowed.', 'migrator'), '', ['response' => 403]);
        }

        $name = isset($_GET['file']) ? sanitize_file_name(wp_unslash((string) $_GET['file'])) : '';
        $path = $this->workspace->path($name);

        // Confine strictly to the workspace directory.
        $realBase = realpath($this->workspace->path());
        $realPath = realpath($path);
        if ('' === $name || false === $realPath || false === $realBase || ! str_starts_with($realPath, $realBase) || ! is_file($realPath)) {
            wp_die(esc_html__('File not found.', 'migrator'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($realPath));

        $handle = fopen($realPath, 'rb');
        if (false !== $handle) {
            while (! feof($handle)) {
                echo fread($handle, 1_048_576); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                flush();
            }
            fclose($handle);
        }
        exit;
    }

    private function guard(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'migrator')], 403);
        }
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'migrator')], 403);
        }
    }

    /**
     * Shape a job for the client: progress + a download URL once finished.
     *
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private function shape(array $job): array
    {
        $total   = max(1, (int) ($job['total'] ?? 0));
        $index   = (int) ($job['index'] ?? 0);
        $done    = 'done' === ($job['status'] ?? '');
        $percent = $done ? 100 : (int) floor($index / $total * 100);

        $shaped = [
            'status'  => (string) ($job['status'] ?? ''),
            'index'   => $index,
            'total'   => (int) ($job['total'] ?? 0),
            'percent' => $percent,
            'done'    => $done,
        ];

        if ($done) {
            $file              = basename((string) ($job['dest'] ?? ''));
            $shaped['bytes']   = (int) ($job['bytes'] ?? 0);
            $shaped['size']    = size_format((int) ($job['bytes'] ?? 0));
            $shaped['fileName'] = $file;
            $shaped['download'] = add_query_arg(
                [
                    'action' => 'migrator_download',
                    'file'   => rawurlencode($file),
                    'nonce'  => wp_create_nonce('migrator_download'),
                ],
                admin_url('admin-ajax.php')
            );
        }

        return $shaped;
    }
}
