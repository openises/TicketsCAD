<?php
/**
 * Channel: MeshCore (Phase 99a #11, 2026-06-28).
 *
 * Thin wrapper around the shared mesh broker code in meshtastic.php.
 * Same queue + bridge contract, different protocol tag.
 */

require_once __DIR__ . '/meshtastic.php';  // pulls in mesh_outbox + shared helpers

broker_register('meshcore', [
    'name'    => 'MeshCore',
    'send'    => '_meshcore_send',
    'receive' => null,
    'status'  => '_meshcore_status',
]);

function _meshcore_send(array $message): array {
    return _mesh_broker_send_common($message, 'meshcore');
}

function _meshcore_status(): string {
    return _mesh_broker_status_common('meshcore');
}
