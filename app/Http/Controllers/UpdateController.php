<?php

namespace App\Http\Controllers;

use App\Services\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UpdateController extends Controller
{
    public function index(UpdateService $updateService): View
    {
        $update = $updateService->getUpdateInfo();

        return view('settings.update', compact('update'));
    }

    public function check(UpdateService $updateService): JsonResponse
    {
        $available = $updateService->checkForUpdate();
        $info = $updateService->getUpdateInfo();

        return response()->json($info);
    }

    public function deploy(UpdateService $updateService): JsonResponse
    {
        $result = $updateService->deploy();

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function status(UpdateService $updateService): JsonResponse
    {
        return response()->json([
            'update_available' => $updateService->isUpdateAvailable(),
        ]);
    }
}
