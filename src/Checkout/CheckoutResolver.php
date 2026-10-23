<?php

declare(strict_types=1);

namespace Nedac\SyliusMinimumOrderValuePlugin\Checkout;

use SM\Factory\FactoryInterface;
use Sylius\Bundle\CoreBundle\Checkout\CheckoutStateUrlGeneratorInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CheckoutResolver implements EventSubscriberInterface
{
    private CartContextInterface $cartContext;
    private CheckoutStateUrlGeneratorInterface $urlGenerator;
    private RequestMatcherInterface $requestMatcher;
    private object $stateMachineFactory;

    public function __construct(
        CartContextInterface $cartContext,
        CheckoutStateUrlGeneratorInterface $urlGenerator,
        RequestMatcherInterface $requestMatcher,
        object $stateMachineFactory
    ) {
        $this->cartContext = $cartContext;
        $this->urlGenerator = $urlGenerator;
        $this->requestMatcher = $requestMatcher;
        $this->stateMachineFactory = $stateMachineFactory;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->requestMatcher->matches($request)) {
            return;
        }

        /** @var OrderInterface $order */
        $order = $this->cartContext->getCart();
        if ($order->isEmpty()) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generateForCart()));
            return;
        }

        $graph = $this->getRequestedGraph($request);
        $transition = $this->getRequestedTransition($request);
        if (null === $graph || null === $transition) {
            return;
        }

        if ($this->canTransition($order, $graph, $transition)) {
            return;
        }

        if (OrderCheckoutTransitions::TRANSITION_ADDRESS === $transition) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generateForCart()));
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generateForOrderCheckoutState($order)));
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    private function getRequestedGraph(Request $request): ?string
    {
        return $request->attributes->get('_sylius', [])['state_machine']['graph'] ?? null;
    }

    private function getRequestedTransition(Request $request): ?string
    {
        return $request->attributes->get('_sylius', [])['state_machine']['transition'] ?? null;
    }

    private function canTransition(OrderInterface $order, string $graph, string $transition): bool
    {
        if ($this->stateMachineFactory instanceof FactoryInterface) {
            return $this->stateMachineFactory->get($order, $graph)->can($transition);
        }

        // Sylius 1.14 injects its state machine abstraction instead of the Winzou factory.
        if (!is_callable([$this->stateMachineFactory, 'can'])) {
            throw new \LogicException('The configured state machine does not support transition checks.');
        }

        return (bool) call_user_func([$this->stateMachineFactory, 'can'], $order, $graph, $transition);
    }
}
