/**
 * Voter field labels — dropdown + proof of purchase (mirrors PHP category_voting_profile_labels).
 */

export const VOTING_PROFILE_LABELS = {
  business: {
    profile: 'business',
    list_instruction: 'Pick your choice from the list, then upload proof of purchase below.',
    proof_label: 'Proof of purchase',
    proof_hint:
      'Upload a photo showing you at the selected establishment — for example, eating BBQ at the restaurant you chose.',
    proof_add_label: 'Add photo',
    validation_message:
      'Please select a business from the list and upload at least one proof-of-purchase photo.',
    validation_message_switch:
      'Please complete your dropdown choice and proof upload before changing categories.',
  },
  media: {
    profile: 'media',
    list_instruction: 'Pick your choice from the list, then upload proof of purchase below.',
    proof_label: 'Proof of purchase',
    proof_hint:
      'Upload a photo that shows your experience with your pick — for example, a screenshot or photo related to the song or artist you chose.',
    proof_add_label: 'Add photo',
    validation_message:
      'Please select your choice from the list and upload at least one proof photo.',
    validation_message_switch:
      'Please complete your dropdown choice and proof upload before changing categories.',
  },
  places: {
    profile: 'places',
    list_instruction: 'Pick your choice from the list, then upload proof of purchase below.',
    proof_label: 'Proof of purchase',
    proof_hint:
      'Upload a photo showing you at the selected place — for example, at the venue or location you chose.',
    proof_add_label: 'Add photo',
    validation_message:
      'Please select a place from the list and upload at least one proof photo.',
    validation_message_switch:
      'Please complete your dropdown choice and proof upload before changing categories.',
  },
  general: {
    profile: 'general',
    list_instruction: 'Pick your choice from the list, then upload proof of purchase below.',
    proof_label: 'Proof of purchase',
    proof_hint: 'Upload a photo that supports your selection.',
    proof_add_label: 'Add photo',
    validation_message:
      'Please select your pick from the list and upload at least one proof photo.',
    validation_message_switch:
      'Please complete your dropdown choice and proof upload before changing categories.',
  },
  mixed: {
    profile: 'mixed',
    list_instruction: 'Pick your choice from the list, then upload proof of purchase below.',
    proof_label: 'Proof of purchase',
    proof_hint:
      'Upload a photo showing your experience with your selection — for example, at the business, place, or related to your pick.',
    proof_add_label: 'Add photo',
    validation_message:
      'Please select your choice from the list and upload at least one proof photo.',
    validation_message_switch:
      'Please complete your dropdown choice and proof upload before changing categories.',
  },
};

/** Mirror of PHP category_voting_profile_infer_from_award_name */
export function inferProfileFromAwardTitle(awardName = '') {
  const n = String(awardName).trim().toLowerCase();
  if (!n) return 'general';

  const phrases = {
    media: ['break up song', 'breakup song', 'love song', 'theme song', 'music video'],
    places: ['date place', 'hangout spot', 'viewing spot', 'tourist spot'],
    business: ['food establishment', 'business establishment', 'food service'],
  };
  for (const [profile, list] of Object.entries(phrases)) {
    if (list.some((phrase) => n.includes(phrase))) return profile;
  }

  const words = {
    media: ['song', 'music', 'anthem', 'opm', 'artist', 'band', 'album', 'tune', 'lyric', 'singer', 'dj'],
    places: ['place', 'venue', 'location', 'park', 'beach', 'resort', 'spot', 'hangout', 'destination', 'view'],
    business: [
      'business', 'establishment', 'store', 'shop', 'salon', 'spa', 'restaurant', 'cafe', 'coffee',
      'hotel', 'bar', 'mall', 'brand', 'company', 'service', 'provider', 'clinic', 'gym',
    ],
  };
  for (const profile of ['media', 'places', 'business']) {
    if (words[profile].some((word) => n.includes(word))) return profile;
  }
  return 'general';
}

export function fieldLabelsForAward(categoryProfile, awardName = '') {
  const profile = String(categoryProfile || 'business').toLowerCase();
  if (profile === 'mixed') {
    const inferred = inferProfileFromAwardTitle(awardName);
    return {
      ...resolveFieldLabels(inferred),
      category_profile: 'mixed',
      inferred_profile: inferred,
    };
  }
  return resolveFieldLabels(profile);
}

export function resolveFieldLabels(profileOrLabels) {
  if (profileOrLabels && typeof profileOrLabels === 'object' && profileOrLabels.proof_label) {
    return profileOrLabels;
  }
  const key = String(profileOrLabels || 'business').toLowerCase();
  return VOTING_PROFILE_LABELS[key] || VOTING_PROFILE_LABELS.business;
}

export function getActiveFieldLabels(state) {
  if (state?.currentFieldLabels?.proof_label) {
    return state.currentFieldLabels;
  }
  return resolveFieldLabels(state?.currentVotingProfile || 'business');
}

/** Per-award labels (mixed category) or category defaults. */
export function getLabelsForQuestion(question, state) {
  if (question?.field_labels?.proof_label) {
    return question.field_labels;
  }
  const profile = state?.currentVotingProfile || 'business';
  if (profile === 'mixed' && question?.question_name) {
    return fieldLabelsForAward('mixed', question.question_name);
  }
  return getActiveFieldLabels(state);
}
