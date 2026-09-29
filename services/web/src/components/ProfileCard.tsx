import type { ReactElement } from 'react';
import { findAction, readBoolean, readString, type SirenSubEntity } from '../siren/index.ts';
import { ActionForm } from './ActionForm.tsx';
import { Avatar } from './Avatar.tsx';
import { Badge } from './Badge.tsx';
import './ProfileCard.css';

/**
 * The `profile` component: a shopper of this browser. The one shopping says so; the others
 * offer the action that switches to them, a button the BFF already worded.
 */
export function ProfileCard({ entity }: { readonly entity: SirenSubEntity }): ReactElement | null {
  const label = readString(entity.properties, 'label');
  const initial = readString(entity.properties, 'initial');
  const profileId = readString(entity.properties, 'profileId');
  const active = readBoolean(entity.properties, 'active') ?? false;
  const useProfile = findAction(entity.actions, 'use-profile');

  if (label === undefined) {
    return null;
  }

  return (
    <li className={`profile-card card${active ? ' profile-card--active' : ''}`}>
      <div className="profile-card__who">
        <Avatar initial={initial ?? label.slice(0, 1)} profileId={profileId} size="large" />
        <div className="profile-card__names">
          <h2 className="profile-card__name">{label}</h2>
          {active ? <Badge tone="success">Perfil atual</Badge> : null}
        </div>
      </div>
      {useProfile !== undefined ? (
        <ActionForm action={useProfile} variant="secondary" className="profile-card__form" />
      ) : null}
    </li>
  );
}
