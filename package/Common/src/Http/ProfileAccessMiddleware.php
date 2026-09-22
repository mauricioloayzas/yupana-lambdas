<?php

namespace App\Common\Http;

use App\Common\Repositories\ProfileRbacRepository;
use App\Common\Repositories\ProfileRepository;
use App\Common\Repositories\RoleRepository;
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

        if (self::isOriginAdmin($rbacs)) {
            return null;
        }

        // Relación directa: el usuario tiene una RBAC propia sobre este perfil puntual.
        foreach ($rbacs as $rbac) {
            if ($rbac->profile_id === $profileId) {
                return null;
            }
        }

        // Owner/Administrator del padre directo (ej. el contador de esta empresa) — un
        // solo nivel, no toda la cadena de ancestros: origin ya se resuelve arriba
        // (isOriginAdmin), y el acceso real de un contador a sus empresas se otorga como
        // RBAC directo (rol "Contador") apenas la suscripción se activa (ver
        // ContadorAccessService); esto es solo la ventana antes de que eso ocurra.
        if (self::isParentOwnerOrAdmin($rbacs, $profileId)) {
            return null;
        }

        return ['statusCode' => 403, 'body' => json_encode(['error' => 'No tienes acceso a este perfil'])];
    }

    /**
     * True si el usuario tiene rol Owner o Administrator sobre el padre directo de
     * $profileId.
     */
    private static function isParentOwnerOrAdmin(array $rbacs, string $profileId): bool
    {
        $profile = (new ProfileRepository())->get($profileId);
        if (!$profile || empty($profile->parent_id)) {
            return false;
        }

        $roleRepo = null;

        foreach ($rbacs as $rbac) {
            if ($rbac->profile_id !== $profile->parent_id) {
                continue;
            }

            $roleRepo ??= new RoleRepository();
            $role = $roleRepo->get($rbac->role_id);

            if ($role && in_array($role->name, ['Owner', 'Administrator'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True si el usuario tiene rol Owner o Administrator sobre el perfil "origin" (raíz de
     * la app, SERVICE_PROFILE_ID) — soporte de plataforma: da acceso a cualquier perfil sin
     * depender de la cadena de parent_id ni de tener un RBAC propio ahí.
     *
     * @param \Mauloasan\BobConstruye\DynamoDB\Entities\Orchestrator\ProfileRbacEntity[] $rbacs
     * Ya cargados por el caller, para no repetir la consulta.
     */
    private static function isOriginAdmin(array $rbacs): bool
    {
        $originId = $_ENV['SERVICE_PROFILE_ID'] ?? '';
        if ($originId === '') {
            return false;
        }

        $roleRepo = null;

        foreach ($rbacs as $rbac) {
            if ($rbac->profile_id !== $originId) {
                continue;
            }

            $roleRepo ??= new RoleRepository();
            $role = $roleRepo->get($rbac->role_id);

            if ($role && in_array($role->name, ['Owner', 'Administrator'], true)) {
                return true;
            }
        }

        return false;
    }
}
