<?php

namespace Oro\Bundle\DistributionBundle\Routing;

use Symfony\Component\Routing\Route;

interface RouteOptionsResolverInterface
{
    /**
     * Performs the route modifications based on its options
     *
     * @param Route                   $route
     * @param RouteCollectionAccessor $routeCollectionAccessor
     */
    public function resolve(Route $route, RouteCollectionAccessor $routeCollectionAccessor);
}
