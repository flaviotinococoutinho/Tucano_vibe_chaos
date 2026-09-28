import type { ReactElement } from 'react';

export type IllustrationName = 'empty' | 'not-found' | 'error' | 'success';

type Entry = {
  readonly file: string;
  readonly alt: string;
};

// The four moments the mascot shows up in the app (docs/assets/README.md). Files are the
// untouched originals, copied byte for byte into public/illustrations.
const ILLUSTRATIONS: Readonly<Record<IllustrationName, Entry>> = {
  empty: {
    file: 'ui-empty.png',
    alt: 'Tucano com capacete de segurança segurando uma caixa de papelão vazia e aberta',
  },
  'not-found': {
    file: 'ui-not-found.png',
    alt: 'Tucano com capacete de segurança lendo um mapa ao lado de uma caixa de papelão com um raio desenhado',
  },
  error: {
    file: 'ui-error.png',
    alt: 'Tucano com capacete de segurança apagando com um extintor um servidor que solta faíscas',
  },
  success: {
    file: 'ui-success.png',
    alt: 'Tucano com capacete de segurança e asas abertas ao lado de uma caixa de papelão marcada com certo, cercado de confete',
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
      width={1024}
      height={1024}
      loading="lazy"
      decoding="async"
      className={className}
    />
  );
}
