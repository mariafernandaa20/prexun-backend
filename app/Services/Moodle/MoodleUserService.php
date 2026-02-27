<?php

namespace App\Services\Moodle;

use Illuminate\Support\Facades\Log;
    
class MoodleUserService extends BaseMoodleService
{
    /**
     * Formatear usuarios para la API de Moodle.
     */
    private function formatUsers(array $users): array
    {
        $formattedUsers = [];

        foreach ($users as $index => $user) {
            foreach ($user as $key => $value) {
                $formattedUsers["users[{$index}][{$key}]"] = is_string($value) ? trim($value) : $value;
            }
        }

        return $formattedUsers;
    }

    /**
     * Obtener usuario por username.
     */
    public function getUserByUsername($username)
    {
        $data = [
            'criteria' => [
                [
                    'key' => 'username',
                    'value' => $username
                ]
            ]
        ];

        $response = $this->sendRequest('core_user_get_users', $data);

        if ($response['status'] === 'success' && isset($response['data']['users'][0])) {
            return [
                'status' => 'success',
                'data' => $response['data']['users'][0]
            ];
        }

        return [
            'status' => 'error',
            'message' => 'User not found or error occurred',
            'response' => $response
        ];
    }

    /**
     * Crear usuarios en Moodle.
     */
    public function createUser(array $users)
    {
        $response = $this->sendRequest('core_user_create_users', $this->formatUsers($users));

        if ($response['status'] === 'success' && !empty($response['data']) && isset($response['data'][0]['id'])) {
            return [
                'status' => 'success',
                'data' => $response['data'],
                'moodle_user_ids' => array_column($response['data'], 'id')
            ];
        }

        $errorMessage = $response['message'] ?? 'Error creating user in Moodle';
        
        return [
            'status' => 'error',
            'message' => $errorMessage,
            'code' => $response['code'] ?? null,
            'debuginfo' => $response['debuginfo'] ?? null,
            'response' => $response
        ];
    }

    /**
     * Actualizar usuarios en Moodle.
     */
    public function updateUser(array $users)
    {
        $response = $this->sendRequest('core_user_update_users', $this->formatUsers($users));

        if ($response['status'] === 'success') {
            return [
                'status' => 'success',
                'data' => $response['data'] ?? null,
            ];
        }

        return [
            'status' => 'error',
            'message' => $response['message'] ?? 'Error updating user in Moodle',
            'response' => $response
        ];
    }    /**
     * Suspender (inactivar) o activar usuarios en Moodle.
     * 
     * @param array $users Array de usuarios con estructura: 
     *                     [['id' => moodle_user_id, 'suspended' => 1|0], ...]
     *                     suspended: 1 = suspender usuario, 0 = activar usuario
     * @return array Respuesta de la API de Moodle
     */
    public function suspendUser(array $users)
    {
        $userCount = count($users);
        $activeUsers = array_filter($users, fn($user) => ($user['suspended'] ?? 0) === 0);
        $suspendedUsers = array_filter($users, fn($user) => ($user['suspended'] ?? 0) === 1);
        
        Log::info('Starting Moodle user suspension/activation operation', [
            'total_users' => $userCount,
            'users_to_activate' => count($activeUsers),
            'users_to_suspend' => count($suspendedUsers),
            'moodle_user_ids' => array_column($users, 'id'),
            'operation_details' => $users
        ]);

        // Validar que los usuarios tengan la estructura correcta
        foreach ($users as $index => $user) {
            if (!isset($user['id']) || !isset($user['suspended'])) {
                Log::error('Invalid user structure in suspendUser operation', [
                    'user_index' => $index,
                    'user_data' => $user,
                    'missing_fields' => [
                        'id_missing' => !isset($user['id']),
                        'suspended_missing' => !isset($user['suspended'])
                    ]
                ]);
                
                return [
                    'status' => 'error',
                    'message' => 'Each user must have "id" and "suspended" fields'
                ];
            }

            if (!is_numeric($user['id']) || !in_array($user['suspended'], [0, 1])) {
                Log::error('Invalid user data in suspendUser operation', [
                    'user_index' => $index,
                    'user_id' => $user['id'],
                    'suspended_value' => $user['suspended'],
                    'id_is_numeric' => is_numeric($user['id']),
                    'suspended_is_valid' => in_array($user['suspended'], [0, 1])
                ]);
                
                return [
                    'status' => 'error',
                    'message' => 'Invalid user data: id must be numeric and suspended must be 0 or 1'
                ];
            }
        }

        Log::info('Sending request to Moodle core_user_update_users', [
            'formatted_users_count' => count($users),
            'api_endpoint' => 'core_user_update_users'
        ]);

        $response = $this->sendRequest('core_user_update_users', $this->formatUsers($users));

        if ($response['status'] === 'success') {
            Log::info('Moodle user suspension/activation completed successfully', [
                'total_users_processed' => $userCount,
                'users_activated' => count($activeUsers),
                'users_suspended' => count($suspendedUsers),
                'moodle_response_data' => $response['data'] ?? [],
                'operation_timestamp' => now()->toISOString()
            ]);
            
            // Log individual user status changes for audit
            foreach ($users as $user) {
                $action = $user['suspended'] === 1 ? 'suspended' : 'activated';
                Log::info("Moodle user {$action} successfully", [
                    'moodle_user_id' => $user['id'],
                    'action' => $action,
                    'suspended_status' => $user['suspended'],
                    'timestamp' => now()->toISOString()
                ]);
            }
            
            return [
                'status' => 'success',
                'data' => $response['data'] ?? [],
                'message' => 'Users suspended/activated successfully'
            ];
        }

        Log::error('Moodle user suspension/activation failed', [
            'total_users' => $userCount,
            'attempted_users' => array_column($users, 'id'),
            'moodle_response' => $response,
            'error_details' => $response['message'] ?? 'Unknown error',
            'operation_timestamp' => now()->toISOString()
        ]);

        return [
            'status' => 'error',
            'message' => 'Error suspending/activating users in Moodle',
            'response' => $response
        ];
    }

    /**
     * Eliminar usuarios de Moodle.
     */
    public function deleteUser($userId)
    {
        Log::info('Deleting user from Moodle', ['moodle_user_id' => $userId]);

        if (is_array($userId)) {
            $data = [];
            foreach ($userId as $index => $id) {
                $data["userids[$index]"] = $id;
            }
        } else {
            $data = [
                'userids[0]' => $userId
            ];
        }

        return $this->sendRequest('core_user_delete_users', $data);
    }
}
