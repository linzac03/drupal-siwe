<?php

namespace Drupal\siwe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Doctrine\Persistence\ManagerRegistry;
use Karhal\Web3ConnectBundle\Event\DataInitializedEvent;
use Karhal\Web3ConnectBundle\Exception\SignatureFailException;
use Karhal\Web3ConnectBundle\Handler\JWTHandler;
use Karhal\Web3ConnectBundle\Handler\MessageHandler;
use Karhal\Web3ConnectBundle\Handler\Web3WalletHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Returns responses for Sign-in with Ethereum routes.
 */
class SiweController extends ControllerBase {
  private ManagerRegistry $registry;
  private Web3WalletHandler $walletHandler;
  private array $configuration;
  private EventDispatcherInterface $eventDispatcher;
  private JWTHandler $JWThandler;
  private CacheInterface $cache;

  public function __construct(ManagerRegistry $registry, Web3WalletHandler $walletHandler, EventDispatcherInterface $eventDispatcher, JWTHandler $JWThandler, CacheInterface $cache)
  {
    $this->registry = $registry;
    $this->walletHandler = $walletHandler;
    $this->eventDispatcher = $eventDispatcher;
    $this->JWThandler = $JWThandler;
    $this->cache = $cache;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('siwe.doctrine'),
      $container->get('siwe.web3connect.wallet_handler'),
      $container->get('event_dispatcher'),
      $container->get('siwe.web3connect.jwt_handler'),
      $container->get('cache.default')
    );
  }

  public function setConfiguration(array $configuration)
  {
    $this->configuration = $configuration;
  }

  public function nonce(Request $request): Response
  {
    $nonce = $this->walletHandler->generateNonce();
    $request->getSession()->set('nonce', $nonce);

    return new JsonResponse(['nonce' => $nonce]);
  }

  public function verify(Request $request): JsonResponse
  {
    $input = $request->getContent();
    $content = \json_decode($input, true);

    $message = MessageHandler::parseMessage($content['message']);
    $signature =  json_decode($input, true)['signature'];

    $rawMessage = $this->walletHandler->prepareMessage($message);

    if (!$this->walletHandler->checkSignature($rawMessage, $signature, $message->getAddress())) {
      throw new SignatureFailException('Signature verification failed');
    }

    if (!$user = $this->registry->getRepository($this->configuration['user_class'])->findOneBy(['walletAddress' => $message->getAddress()])) {
      throw new UserNotFoundException('Unknown user.');
    }

    $event = new DataInitializedEvent();
    $this->eventDispatcher->dispatch($event, $event::NAME);

    $jwt = $this->JWThandler->createJWT(
      [
      'user' => \serialize($user),
      'wallet' => $message->getAddress(),
      ]
    );

    return new JsonResponse(
      [
      'identifier' => $user->getUserIdentifier(),
      'token' => $jwt,
      'data' => $event->getData()
      ]
    );
  }
}
