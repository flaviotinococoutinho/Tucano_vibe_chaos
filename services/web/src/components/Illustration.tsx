import type { ReactElement } from 'react';

export type IllustrationName = 'empty' | 'not-found' | 'error' | 'success';

type Entry = {
  readonly file: string;
  readonly alt: string;
};

// The four moments the mascot shows up in the app (docs/assets/README.md). The originals live
// in docs/assets/ui; these are the copies `make web-art` sizes for the screen.
const ILLUSTRATIONS: Readonly<Record<IllustrationName, Entry>> = {
  empty: {
    file: 'ui-empty.webp',
    alt: 'O tucano espiando dentro de uma sacola de compras vazia',
  },
  'not-found': {
    file: 'ui-not-found.webp',
    alt: 'O tucano olhando para uma placa com três setas em branco, cada uma apontando para um lado',
  },
  error: {
    file: 'ui-error.webp',
    alt: 'O tucano segurando no bico a ponta de um cabo cortado, com a outra ponta no chão',
  },
  success: {
    file: 'ui-success.webp',
    alt: 'O tucano de asa erguida ao lado de uma encomenda fechada com laço',
  },
};

export type IllustrationProps = {
  readonly name: IllustrationName;
  readonly className?: string;
};

export function Illustration({ name, className }: IllustrationProps): ReactElement {
  const { file, alt } = ILLUSTRATIONS[name];
  return (
    <img
      src={`/illustrations/${file}`}
      alt={alt}
      width={512}
      height={512}
      loading="lazy"
      decoding="async"
      className={className}
    />
  );
}
