import type { AnchorHTMLAttributes, MouseEvent, ReactElement, ReactNode } from 'react';
import { toBrowserPath, useHypermedia } from '../hypermedia/index.ts';
import { hasClass, type SirenLink } from '../siren/index.ts';

export type LinkProps = {
  readonly link: SirenLink;
  readonly children?: ReactNode;
} & Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href' | 'children'>;

/**
 * A real `<a href>` for a Siren link. Internal links get client-side navigation on a plain
 * left click; a modified click (middle button, Ctrl/Cmd, Shift) still opens a new tab, because
 * nothing ever calls `preventDefault` for those. A link with class `external` skips all of
 * that and stays a plain link, `target="_blank"` and `rel="noopener"`.
 */
export function Link({ link, children, ...rest }: LinkProps): ReactElement {
  const { navigate } = useHypermedia();
  const label = children ?? link.title ?? link.href;

  if (hasClass(link, 'external')) {
    return (
      <a href={link.href} target="_blank" rel="noopener" {...rest}>
        {label}
      </a>
    );
  }

  const path = toBrowserPath(link.href);
  const { onClick } = rest;

  const handleClick = (event: MouseEvent<HTMLAnchorElement>): void => {
    onClick?.(event);
    if (isPlainLeftClick(event)) {
      event.preventDefault();
      navigate(path);
    }
  };

  return (
    <a {...rest} href={path} onClick={handleClick}>
      {label}
    </a>
  );
}

function isPlainLeftClick(event: MouseEvent<HTMLAnchorElement>): boolean {
  return (
    !event.defaultPrevented &&
    event.button === 0 &&
    !event.metaKey &&
    !event.ctrlKey &&
    !event.shiftKey &&
    !event.altKey
  );
}
