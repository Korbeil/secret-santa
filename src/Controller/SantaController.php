<?php

/*
 * This file is part of the Secret Santa project.
 *
 * (c) JoliCode <coucou@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JoliCode\SecretSanta\Controller;

use JoliCode\SecretSanta\Application\ApplicationInterface;
use JoliCode\SecretSanta\Exception\SecretSantaException;
use JoliCode\SecretSanta\MessageDispatcher;
use JoliCode\SecretSanta\Rudolph;
use JoliCode\SecretSanta\SecretSanta;
use JoliCode\SecretSanta\Spoiler;
use JoliCode\SecretSanta\StatisticCollector;
use JoliCode\SecretSanta\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

class SantaController extends AbstractController
{
    private $router;
    private $twig;
    private $logger;
    private $applications;
    private $statisticCollector;

    public function __construct(RouterInterface $router, \Twig_Environment $twig, LoggerInterface $logger, array $applications, StatisticCollector $statistic)
    {
        $this->router = $router;
        $this->twig = $twig;
        $this->logger = $logger;
        $this->applications = $applications;
        $this->statisticCollector = $statistic;
    }

    public function run(MessageDispatcher $messageDispatcher, Rudolph $rudolph, Request $request, string $application): Response
    {

        $this->logger->error('hgfhgf');
        $application = $this->getApplication($application);

        if (!$application->isAuthenticated()) {
            return new RedirectResponse($this->router->generate($application->getAuthenticationRoute()));
        }

        $allUsers = $application->getUsers();

        $selectedUsers = [];
        $message = null;
        $errors = [];

        if ($request->isMethod('POST')) {
            $selectedUsers = $request->request->get('users', []);
            $message = $request->request->get('message');

            $errors = $this->validate($selectedUsers, $message);

            if (\count($errors) < 1) {
                $associatedUsers = $rudolph->associateUsers($selectedUsers);
                $hash = md5(serialize($associatedUsers));

                $secretSanta = new SecretSanta(
                    $application->getCode(),
                    $application->getOrganization(),
                    $hash,
                    array_filter($allUsers, function (User $user) use ($selectedUsers) {
                        return \in_array($user->getIdentifier(), $selectedUsers, true);
                    }),
                    $associatedUsers,
                    $application->getAdmin(),
                    str_replace('```', '', $message)
                );

                try {
                    $messageDispatcher->dispatchRemainingMessages($secretSanta, $application);
                } catch (SecretSantaException $e) {
                    $this->logger->error($e->getMessage(), [
                        'exception' => $e,
                    ]);
                    $secretSanta->addError($e->getMessage());
                }

                if ($secretSanta->isDone()) {
                    $this->statisticCollector->incrementUsageCount($application->getCode());
                    $application->finish($secretSanta);
                }

                $request->getSession()->set(
                    $this->getSecretSantaSessionKey(
                        $secretSanta->getHash()
                    ), $secretSanta
                );

                return new RedirectResponse($this->router->generate('finish', ['hash' => $secretSanta->getHash()]));
            }
        }

        $content = $this->twig->render('santa/application/run_' . $application->getCode() . '.html.twig', [
            'application' => $application->getCode(),
            'users' => $allUsers,
            'selectedUsers' => $selectedUsers,
            'message' => $message,
            'errors' => $errors,
        ]);

        return new Response($content);
    }

    public function finish(Request $request, string $hash): Response
    {
        $secretSanta = $this->getSecretSantaOrThrow404($request, $hash);

        $content = $this->twig->render('santa/finish.html.twig', [
            'secretSanta' => $secretSanta,
        ]);

        return new Response($content);
    }

    public function spoil(Request $request, Spoiler $spoiler): Response
    {
        $code = $request->request->get('code');
        $invalidCode = false;
        $associations = null;

        if ($code) {
            $associations = $spoiler->decode($code);

            if (null === $associations) {
                $invalidCode = true;
            }
        }

        $content = $this->twig->render('santa/spoil.html.twig', [
            'code' => $code,
            'invalidCode' => $invalidCode,
            'associations' => $associations,
        ]);

        return new Response($content);
    }

    public function retry(MessageDispatcher $messageDispatcher, Request $request, string $hash): Response
    {
        $secretSanta = $this->getSecretSantaOrThrow404($request, $hash);
        $application = $this->getApplication($secretSanta->getApplication());

        if (!$application->isAuthenticated()) {
            return new RedirectResponse($this->router->generate($application->getAuthenticationRoute()));
        }

        try {
            $messageDispatcher->dispatchRemainingMessages($secretSanta, $application);
        } catch (SecretSantaException $e) {
            $this->logger->error($e->getMessage(), [
                'exception' => $e,
            ]);
            $secretSanta->addError($e->getMessage());
        }

        if ($secretSanta->isDone()) {
            $application->finish($secretSanta);
        }

        $request->getSession()->set(
            $this->getSecretSantaSessionKey(
                $secretSanta->getHash()
            ), $secretSanta
        );

        return new RedirectResponse($this->router->generate('finish', ['hash' => $secretSanta->getHash()]));
    }

    private function getApplication(string $code): ApplicationInterface
    {
        foreach ($this->applications as $application) {
            if ($application->getCode() === $code) {
                return $application;
            }
        }

        throw $this->createNotFoundException(sprintf('Unknown application %s', $code));
    }

    private function getSecretSantaSessionKey(string $hash): string
    {
        return sprintf('secret-santa-%s', $hash);
    }

    private function getSecretSantaOrThrow404(Request $request, string $hash): SecretSanta
    {
        $secretSanta = $request->getSession()->get(
            $this->getSecretSantaSessionKey(
                $hash
            )
        );

        if (!$secretSanta) {
            throw $this->createNotFoundException('No secret santa found in session');
        }

        return $secretSanta;
    }

    private function validate(array $selectedUsers, string $message): array
    {
        $errors = [];

        if (\count($selectedUsers) < 2) {
            $errors['users'][] = 'At least 2 users should be selected';
        }

        return $errors;
    }
}
