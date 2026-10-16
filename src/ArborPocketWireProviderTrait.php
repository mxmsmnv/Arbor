<?php namespace ProcessWire;

/** Status-only PocketWire surface; genealogy inventory and records stay private. */
trait ArborPocketWireProviderTrait
{
    public function pocketWireProviderInfo(): array
    {
        return ['id' => 'arbor', 'title' => 'Arbor', 'module' => 'Arbor', 'moduleVersion' => '1.0.3'];
    }

    public function pocketWireManifest(User $actor): array
    {
        $ready = $this->pocketWireCanView($actor);
        return [
            'id' => 'arbor', 'title' => 'Arbor', 'module' => 'Arbor', 'moduleVersion' => '1.0.3',
            'status' => $ready ? 'native_read_only' : 'unsupported',
            'permissions' => ['scope' => 'providers.read', 'processWire' => ['arbor-view']],
            'presentation' => ['layout' => 'dashboard', 'icon' => 'git-network-outline', 'summary' => 'Local Arbor module status.', 'accentColor' => '#15803D'],
            'resources' => $ready ? [['id' => 'status', 'title' => 'Status', 'method' => 'GET', 'endpoint' => '/api/pocketwire/v1/providers/arbor/status', 'presentation' => ['layout' => 'metrics']]] : [],
            'actions' => [],
        ];
    }

    public function pocketWireRead(string $resource, string $identifier, array $query, User $actor): array
    {
        if(!$this->pocketWireCanView($actor)) throw new WirePermissionException('The arbor-view permission is required.');
        if($resource !== 'status' || $identifier !== '') throw new Wire404Exception('Arbor resource was not found.');
        return [
            'version' => '1.0.3',
            'module_ready' => true,
            'genealogy_records_exposed' => false,
            'public_inventory_exposed' => false,
            'living_person_data_exposed' => false,
            'write_tools' => false,
        ];
    }

    private function pocketWireCanView(User $actor): bool
    {
        $current = $this->wire('user');
        return $current instanceof User
            && (int)$current->id === (int)$actor->id
            && ($actor->isSuperuser() || ($actor->isLoggedin() && $actor->hasPermission('arbor-view')));
    }
}
