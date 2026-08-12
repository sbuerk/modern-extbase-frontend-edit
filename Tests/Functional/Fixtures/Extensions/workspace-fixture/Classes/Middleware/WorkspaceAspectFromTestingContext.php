<?php

declare(strict_types=1);

namespace TESTS\WorkspaceFixture\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Puts the frontend sub-request into the workspace the test asked for.
 *
 * ## Why this exists, and what it replaces
 *
 * `InternalRequestContext::withWorkspaceId()` alone does nothing here. The
 * testing framework applies it in its `BackendUserHandler` middleware, which
 * returns before it looks at the workspace when no backend user id is set, and
 * then calls `BackendUserAuthentication::setTemporaryWorkspace()`. That method
 * resolves the workspace through `checkWorkspace()`, which reads the
 * `sys_workspace` schema — TCA and table alike ship with EXT:workspaces, and
 * this extension does not depend on it, so it is **not installed** in the
 * dependency set of this repository. Without it `checkWorkspace()` returns
 * `false`, the workspace is never set, and a test asserting the workspace guard
 * would run in the live workspace and pass for the wrong reason.
 *
 * What the guard under test reads is the `workspace` aspect of the shared
 * `Context` — `WorkspaceGuard` asks it for `isLive`, exactly as
 * `Typo3DbQueryParser` and `PageRepository` do. This middleware sets that
 * aspect, which is the same thing `BackendUserAuthenticator` does in a real
 * frontend request, and nothing else. It does not simulate workspace *records*,
 * overlays or previews, and no test may claim it does.
 *
 * It is inert unless a test explicitly passes a workspace id, so loading the
 * fixture extension changes nothing for the tests that do not.
 *
 * ## Placement in the stack
 *
 * After `typo3/cms-frontend/prepare-tsfe-rendering`, which is after
 * `typo3/cms-frontend/backend-user-authentication` — the middleware that would
 * otherwise be the last writer of this aspect and would overwrite whatever is
 * set before it.
 *
 * The service is published (`public: true`) because
 * `MiddlewareDispatcher::lazy()` fetches middlewares from the container and
 * falls back to `GeneralUtility::makeInstance()` — without constructor
 * injection — when `ContainerInterface::has()` answers `false` for a private
 * service. That is the same framework constraint TYPO3 core resolves by
 * declaring every one of its middlewares public.
 */
#[Autoconfigure(public: true)]
final readonly class WorkspaceAspectFromTestingContext implements MiddlewareInterface
{
    public function __construct(
        private Context $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $testingContext = $request->getAttribute('typo3.testing.context');
        if ($testingContext instanceof InternalRequestContext) {
            $workspaceId = $testingContext->getWorkspaceId();
            if ($workspaceId !== null) {
                $this->context->setAspect('workspace', new WorkspaceAspect($workspaceId));
            }
        }

        return $handler->handle($request);
    }
}
