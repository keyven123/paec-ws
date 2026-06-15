<?php

namespace App\Http\Controllers;

use App\Http\Repositories\UploadRepository;
use App\Http\Requests\Upload\UploadRequest;
use App\Http\Resources\UploadResource;
use App\Exceptions\NoUploadFoundException;
use App\Http\Requests\Upload\DeleteUploadRequest;
use App\Http\Requests\Upload\GlobalUploadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UploadController extends Controller
{
    public function __construct(protected UploadRepository $uploadRepository)
    {
    }

    /**
     * Display the specified upload.
     * @param string $uuid
     * @return JsonResponse
     * @throws NoUploadFoundException
     */
    public function show(string $uuid): JsonResponse
    {
        $upload = $this->uploadRepository->fetchOrThrow('uuid', $uuid);
        return (new UploadResource($upload))->response();
    }

    public function globalUpload(GlobalUploadRequest $request)
    {
        $validated = $request->validated();
        $upload = $this->uploadRepository->createGlobalUpload($validated);
        return (new UploadResource($upload))->response();
    }

    public function store(UploadRequest $request)
    {
        $validated = $request->validated();
        $upload = $this->uploadRepository->create($validated);

        return (new UploadResource($upload))->response();
    }

    public function destroy(DeleteUploadRequest $request): Response
    {
        $payload = $request->validated();
        $upload = $this->uploadRepository->fetchOrThrow('uuid', $payload['uuid']);
        $this->uploadRepository->delete($upload, $payload);
        return $this->noContent();
    }

    /**
     * Proxy an external image and return it as base64
     * This is used to bypass CORS restrictions for PDF generation
     *
     * @param \Illuminate\Http\Request $request
     * @return JsonResponse
     */
    public function proxyImage(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url'
        ]);

        $imageUrl = $request->input('url');

        try {
            // Fetch the image using Guzzle or file_get_contents
            $imageContent = @file_get_contents($imageUrl);

            if ($imageContent === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch image'
                ], 400);
            }

            // Get mime type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $imageContent);
            finfo_close($finfo);

            // Convert to base64
            $base64 = base64_encode($imageContent);
            $dataUrl = 'data:' . $mimeType . ';base64,' . $base64;

            return response()->json([
                'success' => true,
                'data' => [
                    'dataUrl' => $dataUrl,
                    'mimeType' => $mimeType
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to proxy image: ' . $e->getMessage()
            ], 500);
        }
    }
}
