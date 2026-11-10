<?php
/**
 * /api/v1/templates.php
 *
 *   GET ?type=note|page[&tz=Europe/Oslo]        list, placeholders filled in for that time zone
 *   POST   {type, name, content}                create (409 if it exists)
 *   PUT    {type, current_name, new_name?, content}   update / rename
 *   DELETE {type, name}                         delete
 *
 * PUT and DELETE may be sent as POST with "_method" in the body.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\ApiResponse;
use App\TemplateException;
use App\Templates;

$input = notd_json_input();

try {
    switch (notd_request_method()) {
        case 'GET':
            $tz = isset($_GET['tz']) && is_string($_GET['tz']) ? $_GET['tz'] : null;
            ApiResponse::success((new Templates((string)($_GET['type'] ?? 'note')))->all($tz));
            break;

        case 'POST':
            $templates = new Templates((string)($input['type'] ?? ''));
            if (!isset($input['name'], $input['content']) || !is_string($input['content'])) {
                ApiResponse::error('Template name and content are required', 400);
                break;
            }
            if ($templates->exists($input['name'])) {
                ApiResponse::error('A template with this name already exists.', 409);
                break;
            }
            $templates->save($input['name'], $input['content']);
            ApiResponse::success(['message' => 'Template created successfully'], 201);
            break;

        case 'PUT':
            $templates = new Templates((string)($input['type'] ?? ''));
            if (!isset($input['current_name'], $input['content']) || !is_string($input['content'])) {
                ApiResponse::error('Current template name and content are required for update', 400);
                break;
            }
            if (!$templates->exists($input['current_name'])) {
                ApiResponse::error('Template not found', 404);
                break;
            }
            $newName = $input['new_name'] ?? $input['current_name'];
            $templates->save($newName, $input['content']); // write first: a failure leaves the old one intact
            if ($newName !== $input['current_name']) {
                $templates->delete($input['current_name']);
            }
            ApiResponse::success(['message' => 'Template updated successfully']);
            break;

        case 'DELETE':
            (new Templates((string)($input['type'] ?? '')))->delete($input['name'] ?? null);
            ApiResponse::success(['message' => 'Template deleted successfully']);
            break;

        default:
            ApiResponse::error('Method not allowed', 405);
    }
} catch (TemplateException $e) {
    ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
}
