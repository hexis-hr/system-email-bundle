<?php

declare(strict_types=1);

namespace Hexis\SystemEmailBundle\Controller\Admin;

use Hexis\SystemEmailBundle\Entity\SystemEmailRecipient;
use Hexis\SystemEmailBundle\Entity\SystemEmailType;
use Hexis\SystemEmailBundle\Form\RecipientFormType;
use Hexis\SystemEmailBundle\Repository\SystemEmailRecipientRepository;
use Hexis\SystemEmailBundle\Repository\SystemEmailTypeRepository;
use Hexis\SystemEmailBundle\Service\SystemEmailManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backend management of email types and their recipients.
 *
 * Types are not created or deleted here (they come from the YAML registry via
 * system-email:sync); the UI toggles the per-type send switch and performs full
 * CRUD on recipients.
 */
final class SystemEmailController extends AbstractController
{
    public function __construct(
        private SystemEmailManager $manager,
        private SystemEmailTypeRepository $typeRepository,
        private SystemEmailRecipientRepository $recipientRepository,
        private string $adminRole,
        private string $baseTemplate,
        private string $translationDomain,
    ) {
    }

    public function index(): Response
    {
        $this->denyUnlessAllowed();

        return $this->render('@SystemEmail/admin/index.html.twig', $this->viewContext([
            'types' => $this->typeRepository->findAllOrdered(),
        ]));
    }

    public function toggleType(string $key, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $type = $this->getTypeOr404($key);
        $this->assertCsrf('toggle-type-' . $type->getId(), $request);

        $this->manager->setTypeEnabled($type, !$type->isEnabled());

        return $this->redirectToRoute('system_email_admin_index');
    }

    public function recipients(string $key): Response
    {
        $this->denyUnlessAllowed();
        $type = $this->getTypeOr404($key);

        return $this->render('@SystemEmail/admin/recipients.html.twig', $this->viewContext([
            'type' => $type,
            'recipients' => $type->getRecipients(),
        ]));
    }

    public function newRecipient(string $key, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $type = $this->getTypeOr404($key);

        $recipient = (new SystemEmailRecipient())->setType($type);
        $form = $this->createForm(RecipientFormType::class, $recipient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->saveRecipient($recipient);

            return $this->redirectToRoute('system_email_admin_recipients', ['key' => $type->getKey()]);
        }

        return $this->render('@SystemEmail/admin/new.html.twig', $this->viewContext([
            'type' => $type,
            'form' => $form->createView(),
        ]));
    }

    public function editRecipient(int $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $recipient = $this->getRecipientOr404($id);

        $form = $this->createForm(RecipientFormType::class, $recipient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->manager->saveRecipient($recipient);

            return $this->redirectToRoute('system_email_admin_recipients', ['key' => $recipient->getType()->getKey()]);
        }

        return $this->render('@SystemEmail/admin/edit.html.twig', $this->viewContext([
            'type' => $recipient->getType(),
            'recipient' => $recipient,
            'form' => $form->createView(),
        ]));
    }

    public function toggleRecipient(int $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $recipient = $this->getRecipientOr404($id);
        $this->assertCsrf('toggle-recipient-' . $recipient->getId(), $request);

        $this->manager->setRecipientActive($recipient, !$recipient->isActive());

        return $this->redirectToRoute('system_email_admin_recipients', ['key' => $recipient->getType()->getKey()]);
    }

    public function deleteRecipient(int $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $recipient = $this->getRecipientOr404($id);
        $this->assertCsrf('delete-recipient-' . $recipient->getId(), $request);

        $key = $recipient->getType()->getKey();
        $this->manager->deleteRecipient($recipient);

        return $this->redirectToRoute('system_email_admin_recipients', ['key' => $key]);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function viewContext(array $context): array
    {
        return array_merge([
            'base_template' => $this->baseTemplate,
            'translation_domain' => $this->translationDomain,
        ], $context);
    }

    private function getTypeOr404(string $key): SystemEmailType
    {
        $type = $this->typeRepository->findOneByKey($key);
        if ($type === null) {
            throw new NotFoundHttpException(sprintf('Email type "%s" not found.', $key));
        }

        return $type;
    }

    private function getRecipientOr404(int $id): SystemEmailRecipient
    {
        $recipient = $this->recipientRepository->find($id);
        if ($recipient === null) {
            throw new NotFoundHttpException(sprintf('Recipient #%d not found.', $id));
        }

        return $recipient;
    }

    private function denyUnlessAllowed(): void
    {
        if ($this->container->has('security.authorization_checker')) {
            $this->denyAccessUnlessGranted($this->adminRole);
        }
    }

    private function assertCsrf(string $id, Request $request): void
    {
        if (!$this->container->has('security.csrf.token_manager')) {
            return;
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
