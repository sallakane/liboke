<?php

declare(strict_types=1);

namespace App\Content;

use App\Image\ImageVariants;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Renderer\Inline\ImageRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Xml\XmlNodeRendererInterface;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;
use Stringable;

/**
 * Images du contenu Markdown : `![Texte alternatif](images/contenu/photo.jpg)`
 * devient un <picture> responsive, si l'image a ses variantes
 * (`make images`). Sinon, rendu CommonMark standard.
 *
 * L'association dépose une photo dans assets/images/contenu/, la cite dans
 * sa page : WebP, tailles et chargement différé suivent sans autre réglage.
 */
final readonly class ResponsiveImageRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{
    private const string PREFIXE = 'images/';

    /** Le contenu est affiché dans une colonne de 70 caractères au plus. */
    private const string SIZES = '(min-width: 45rem) 42rem, 100vw';

    public function __construct(
        private ImageVariants $images,
        private ImageRenderer $standard = new ImageRenderer(),
    ) {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        Image::assertInstanceOf($node);
        \assert($node instanceof Image);

        $url = $node->getUrl();
        $source = str_starts_with($url, self::PREFIXE) ? substr($url, \strlen(self::PREFIXE)) : null;

        if (null === $source || !$this->images->has($source)) {
            return $this->standard->render($node, $childRenderer);
        }

        // Même calcul du texte alternatif que le rendu standard : le texte
        // brut des nœuds enfants.
        $alt = $childRenderer->renderNodes($node->children());
        $alt = html_entity_decode(strip_tags((string) $alt), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return new HtmlElement('span', ['class' => 'image-contenu'], $this->images->picture($source, $alt, self::SIZES));
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->standard->setConfiguration($configuration);
    }

    public function getXmlTagName(Node $node): string
    {
        return $this->standard->getXmlTagName($node);
    }

    /**
     * @return array<string, scalar>
     */
    public function getXmlAttributes(Node $node): array
    {
        \assert($node instanceof Image);

        return $this->standard->getXmlAttributes($node);
    }
}
