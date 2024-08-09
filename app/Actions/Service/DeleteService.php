<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Actions\Server\CleanupDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteService
{
    use AsAction;

    public function handle(Service $service, bool $deleteConfigurations, bool $deleteVolumes, bool $deleteImages, bool $deleteConnectedNetworks)
    {
        try {
            $server = data_get($service, 'server');
            if ($deleteVolumes && $server->isFunctional()) {
                $storagesToDelete = collect([]);

                $service->environment_variables()->delete();
                $commands = [];
                foreach ($service->applications()->get() as $application) {
                    $storages = $application->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($service->databases()->get() as $database) {
                    $storages = $database->persistentStorages()->get();
                    foreach ($storages as $storage) {
                        $storagesToDelete->push($storage);
                    }
                }
                foreach ($storagesToDelete as $storage) {
                    $commands[] = "docker volume rm -f $storage->name";
                }

                // Execute volume deletion first, this must be done first otherwise volumes will not be deleted.
                if (!empty($commands)) {
                    foreach ($commands as $command) {
                        $result = instant_remote_process([$command], $server, false);
                        if ($result !== 0) {
                            ray("Failed to execute: $command");
                        }
                    }
                }
            }

            if ($deleteConnectedNetworks) {
                $service->delete_connected_networks($service->uuid);
            }

            $commands[] = "docker rm -f $service->uuid";

            // Executing remaining commands
            instant_remote_process($commands, $server, false);
            
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        } finally {
            if ($deleteConfigurations) {
                $service->delete_configurations();
            }
            foreach ($service->applications()->get() as $application) {
                $application->forceDelete();
            }
            foreach ($service->databases()->get() as $database) {
                $database->forceDelete();
            }
            foreach ($service->scheduled_tasks as $task) {
                $task->delete();
            }
            $service->tags()->detach();
            $service->forceDelete();

            if ($deleteImages) {
                CleanupDocker::run($server, true);
            }
        }
    }
}
