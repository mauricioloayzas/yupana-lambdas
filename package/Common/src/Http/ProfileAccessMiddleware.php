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

        $targetProfile = (new ProfileRepository())->get($profileId);
        $parentId = $targetProfile->parent_id ?? null;

        if (!ProfileAccessGuard::hasAccess($profileId, $parentId, $ownedProfileIds)) {
            return ['statusCode' => 403, 'body' => json_encode(['error' => 'No tienes acceso a este perfil'])];
        }

        return null;
    }
}
