<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ProfileController extends Controller
{
    /**
     * Update user profile name
     */
    public function updateUsername(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|max:20|regex:/^[a-zA-Z0-9_]+$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Username must be 3-20 characters and contain only letters, numbers, and underscores',
                'errors' => $validator->errors()
            ], 422);
        }

        $username = $this->sanitizeUsername($request->username);

        // Check if username is already taken
        $existingUser = \App\Models\User::where('name', $username)
            ->where('id', '!=', auth()->id())
            ->first();

        if ($existingUser) {
            return response()->json([
                'success' => false,
                'message' => 'Username is already taken'
            ], 422);
        }

        // Update user
        $user = auth()->user();
        $user->update(['name' => $username]);

        return response()->json([
            'success' => true,
            'message' => 'Username updated successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'phone' => $user->phone,
                    'language' => $user->locale,
                    'tokens' => $user->tokens_balance,
                    'level' => $user->level,
                    'experience' => $user->experience,
                    'can_claim_daily_bonus' => $user->canClaimDailyBonus()
                ]
            ]
        ]);
    }

    /**
     * Update user avatar
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        // Add detailed debugging before validation
        \Log::info('Avatar upload attempt:', [
            'has_file' => $request->hasFile('avatar'),
            'all_files' => $request->allFiles(),
            'all_input' => $request->all(),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method(),
            'file_size' => $request->hasFile('avatar') ? $request->file('avatar')->getSize() : 'no file',
            'file_type' => $request->hasFile('avatar') ? $request->file('avatar')->getMimeType() : 'no file',
            'file_name' => $request->hasFile('avatar') ? $request->file('avatar')->getClientOriginalName() : 'no file',
            'max_upload_size' => ini_get('upload_max_filesize'),
            'max_post_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit')
        ]);

        // Manual validation instead of Laravel validator
        if (!$request->hasFile('avatar')) {
            return response()->json([
                'success' => false,
                'message' => 'No file uploaded',
                'debug' => ['error' => 'no_file']
            ], 422);
        }

        $file = $request->file('avatar');
        
        // Check file size manually (2MB = 2097152 bytes)
        if ($file->getSize() > 2097152) {
            return response()->json([
                'success' => false,
                'message' => 'File size must be less than 2MB',
                'debug' => [
                    'file_size' => $file->getSize(),
                    'file_size_mb' => round($file->getSize() / (1024 * 1024), 2),
                    'max_allowed_mb' => 2
                ]
            ], 422);
        }

        // Check if it's actually an image by trying to read it
        try {
            $imageInfo = getimagesize($file->getPathname());
            if (!$imageInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'The uploaded file is not a valid image',
                    'debug' => ['error' => 'invalid_image_format']
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded file is not a valid image',
                'debug' => ['error' => 'image_processing_failed', 'exception' => $e->getMessage()]
            ], 422);
        }

        $user = auth()->user();

        \Log::info('Avatar validation passed, processing upload:', [
            'file_size' => $file->getSize(),
            'file_type' => $file->getMimeType(),
            'user_id' => $user->id
        ]);

        try {
            // Delete old avatar if exists
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }

            // Process uploaded image
            $uploadedFile = $request->file('avatar');
            $filename = 'avatars/' . Str::uuid() . '.jpg';
            
            // Create image manager with GD driver
            $manager = new ImageManager(new Driver());
            $image = $manager->read($uploadedFile);
            
            // Resize and crop to circle (200x200)
            $image->cover(200, 200);
            
            // Save as JPEG with 85% quality
            $processedImage = $image->toJpeg(85);
            
            // Store the processed image
            Storage::disk('public')->put($filename, $processedImage);

            // Update user avatar
            $user->update(['avatar' => $filename]);

            return response()->json([
                'success' => true,
                'message' => 'Avatar updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar' => $user->avatar,
                        'phone' => $user->phone,
                        'language' => $user->locale,
                        'tokens' => $user->tokens_balance,
                        'level' => $user->level,
                        'experience' => $user->experience,
                        'can_claim_daily_bonus' => $user->canClaimDailyBonus()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload avatar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check username availability
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|max:20|regex:/^[a-zA-Z0-9_]+$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Username must be 3-20 characters and contain only letters, numbers, and underscores'
            ]);
        }

        $username = $this->sanitizeUsername($request->username);

        $exists = \App\Models\User::where('name', $username)
            ->where('id', '!=', auth()->id())
            ->exists();

        return response()->json([
            'success' => true,
            'available' => !$exists,
            'message' => $exists ? 'Username is already taken' : 'Username is available'
        ]);
    }

    /**
     * Get user profile
     */
    public function show(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'phone' => $user->phone,
                    'language' => $user->locale,
                    'tokens' => $user->tokens_balance,
                    'level' => $user->level,
                    'experience' => $user->experience,
                    'can_claim_daily_bonus' => $user->canClaimDailyBonus(),
                    'experience_to_next_level' => $user->experienceToNextLevel()
                ]
            ]
        ]);
    }

    /**
     * Sanitize username by removing spaces and special characters
     */
    private function sanitizeUsername(string $username): string
    {
        // Remove spaces and convert to lowercase
        $sanitized = strtolower(trim($username));
        
        // Remove any character that's not alphanumeric or underscore
        $sanitized = preg_replace('/[^a-z0-9_]/', '', $sanitized);
        
        // Ensure it doesn't start with underscore or number
        $sanitized = preg_replace('/^[_0-9]+/', '', $sanitized);
        
        // Limit length
        $sanitized = substr($sanitized, 0, 20);
        
        return $sanitized;
    }
}
