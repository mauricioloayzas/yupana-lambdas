<?php

namespace App\Common\Http;

use App\Common\Repositories\ProfileRbacRepository;
use App\Common\Repositories\ProfileRepository;
use Mauloasan\BobConstruye\Http\ProfileAccessGuard;

class ProfileAccessMiddleware
{
    /**
     * Valida que el usuario autenticado tenga acceso al profile_id solicitado: porque es
     * el propio perfil, o porque es el contador (perfil padre) dueño de ese perfil.
     *
     * @return array|null Respuesta 401/403 si el acceso está denegado, o null si está permitido.
     */
    public static function check(array $event, string $profileId): ?array
    {
        if (ProfileAccessGuard::isInternalInvocation($event)) {
            return null;
        }

        $userId = ProfileAccessGuard::extractUserId($event);

        if (!$userId) {
            return ['statusCode' => 401, 'body' => json_encode(['error' => 'No se pudo identificar al usuario autenticado'])];
        }

        $rbacs = (new ProfileRbacRepository())->getProfileRbacsByUserId($userId) ?? [];
        $ownedProfileIds = array_map(fn($rbac) => $rbac->profile_id, $rbacs);

        $ancestorIds = self::resolveAncestorChain($profileId);

        if (!ProfileAccessGuard::hasAccessViaAncestors($profileId, $ancestorIds, $ownedProfileIds)) {
            return ['statusCode' => 403, 'body' => json_encode(['error' => 'No tienes acceso a este perfil'])];
        }

        return null;
    }

    /**
     * Camina parent_id hacia arriba desde $profileId (sin incluirlo) hasta la raíz, con un
     * tope de profundidad como salvaguarda — la jerarquía real hoy es como mucho
     * origin → contador → company (2 niveles). Necesario para que alguien con RBAC solo en
     * el perfil "origin" tenga acceso también a las empresas administradas por un contador,
     * no solo a las que cuelgan directamente de origin.
     *
     * @return string[]
     */
    private static function resolveAncestorChain(string $profileId, int $maxDepth = 4): array
    {
        $profileRepo = new ProfileRepository();
        $ancestorIds = [];
        $currentId   = $profileId;

        for ($i = 0; $i < $maxDepth; $i++) {
            $currentProfile = $profileRepo->get($currentId);
            $parentId       = $currentProfile->parent_id ?? null;

            if ($parentId === null) {
                break;
            }

            $ancestorIds[] = $parentId;
            $currentId     = $parentId;
        }

        return $ancestorIds;
    }
}
