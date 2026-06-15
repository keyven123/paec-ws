<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\VenueRepository;
use App\Http\Requests\Venue\CreateVenueRequest;
use App\Http\Requests\Venue\UpdateVenueRequest;
use App\Http\Requests\Venue\ListVenueRequest;
use App\Http\Resources\VenueResource;
use App\Exceptions\NoResourceFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Requests\Venue\PublicPlacesRequest;
use App\Http\Resources\PublicPlaceResource;
use App\Models\Place;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class VenueController extends Controller
{
    public function __construct(protected VenueRepository $venueRepository)
    {
    }

    /**
     * Display a listing of venues.
     * @param ListVenueRequest $request
     * @return JsonResponse
     */
    public function index(ListVenueRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->venueRepository->getAll($request->validated());
        return VenueResource::collection($list->paginate($perPage))->response();
    }

    public function publicPlaces(PublicPlacesRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $list = $this->venueRepository->getPublicPlaces($payload);
        return PublicPlaceResource::collection($list->get())->response();
    }

    /**
     * Store a newly created venue.
     * @param CreateVenueRequest $request
     * @return JsonResponse
     */
    public function store(CreateVenueRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $venue = $this->venueRepository->create($payload);
        return (new VenueResource($venue))->response()->setStatusCode(201);
    }

    /**
     * Display the specified venue.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $venue = $this->venueRepository->fetchOrThrow('uuid', $uuid);
            return (new VenueResource($venue))->response();
        } catch (NoResourceFoundException $e) {
            throw new NoResourceFoundException('No venue found');
        }
    }

    /**
     * Update the specified venue.
     * @param UpdateVenueRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdateVenueRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $venue = $this->venueRepository->fetchOrThrow('uuid', $uuid);
            $this->venueRepository->update($venue, $payload);
            return (new VenueResource($venue->fresh()))->response();
        } catch (NoResourceFoundException $e) {
            throw new NoResourceFoundException('No venue found');
        }
    }

    /**
     * Remove the specified venue from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $venue = $this->venueRepository->fetchOrThrow('uuid', $uuid);
            $this->venueRepository->delete($venue);
            return $this->noContent();
        } catch (NoResourceFoundException $e) {
            throw new NoResourceFoundException('No venue found');
        } catch (UnauthorizedException $e) {
            throw new UnauthorizedException();
        }
    }
}
