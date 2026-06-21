/**
 * Voter manual-entry labels by category voting profile (mirrors PHP category_voting_profile_labels).
 */

export const VOTING_PROFILE_LABELS = {
  business: {
    profile: 'business',
    instruction: "Can't find your choice in the list? Enter it below.",
    list_instruction: 'Pick from the list. If you do not see your choice, type it in the box below.',
    field1_label: 'Name of your choice',
    field1_placeholder: 'Example: Best Chicken Barbecue',
    field2_label: 'Business name',
    field2_placeholder: "Example: Angel's Burger",
    other_label: 'If not on the list, type your choice here',
    other_placeholder: 'Example: Best Chicken Barbecue',
    validation_message: 'Please fill in both boxes: your choice and the business name.',
    validation_message_switch:
      'Please fill in both boxes (your choice and business name) before changing categories.',
  },
  media: {
    profile: 'media',
    instruction: "Can't find your song in the list? Enter it below.",
    list_instruction: 'Pick from the list. If you do not see your choice, type it in the box below.',
    field1_label: 'Song title',
    field1_placeholder: 'Example: Levitating',
    field2_label: 'Artist name',
    field2_placeholder: 'Example: Dua Lipa',
    other_label: 'If not on the list, type the song here',
    other_placeholder: 'Example: Levitating',
    validation_message: 'Please fill in both boxes: song title and artist name.',
    validation_message_switch:
      'Please fill in both boxes (song and artist) before changing categories.',
  },
  places: {
    profile: 'places',
    instruction: "Can't find your place in the list? Enter it below.",
    list_instruction: 'Pick from the list. If you do not see your choice, type it in the box below.',
    field1_label: 'Place name',
    field1_placeholder: 'Example: Tierra Verde',
    field2_label: 'City or area',
    field2_placeholder: 'Example: Ormoc City',
    other_label: 'If not on the list, type the place here',
    other_placeholder: 'Example: Tierra Verde',
    validation_message: 'Please fill in both boxes: place name and city or area.',
    validation_message_switch:
      'Please fill in both boxes (place and area) before changing categories.',
  },
  general: {
    profile: 'general',
    instruction: "Can't find your pick in the list? Enter it below.",
    list_instruction: 'Pick from the list. If you do not see your choice, type it in the box below.',
    field1_label: 'Your pick',
    field1_placeholder: 'Example: Best date spot',
    field2_label: 'Extra detail',
    field2_placeholder: 'Example: artist, location, or name',
    other_label: 'If not on the list, type your pick here',
    other_placeholder: 'Type your answer',
    validation_message: 'Please fill in both boxes: your pick and the extra detail.',
    validation_message_switch: 'Please fill in both boxes before changing categories.',
  },
  mixed: {
    profile: 'mixed',
    instruction: "Can't find your choice in the list? Enter it below.",
    list_instruction: 'Pick from the list. If you do not see your choice, type it in the box below.',
    field1_label: 'Your pick',
    field1_placeholder: 'Type your answer',
    field2_label: 'Extra detail',
    field2_placeholder: 'Example: artist, place, or business name',
    other_label: 'If not on the list, type your choice here',
    other_placeholder: 'Type your answer',
    validation_message: 'Please fill in both boxes for this award.',
    validation_message_switch:
      'Please fill in both boxes for the current award before changing categories.',
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
  if (profileOrLabels && typeof profileOrLabels === 'object' && profileOrLabels.field1_label) {
    return profileOrLabels;
  }
  const key = String(profileOrLabels || 'business').toLowerCase();
  return VOTING_PROFILE_LABELS[key] || VOTING_PROFILE_LABELS.business;
}

export function getActiveFieldLabels(state) {
  if (state?.currentFieldLabels?.field1_label) {
    return state.currentFieldLabels;
  }
  return resolveFieldLabels(state?.currentVotingProfile || 'business');
}

/** Per-award labels (mixed category) or category defaults. */
export function getLabelsForQuestion(question, state) {
  if (question?.field_labels?.field1_label) {
    return question.field_labels;
  }
  const profile = state?.currentVotingProfile || 'business';
  if (profile === 'mixed' && question?.question_name) {
    return fieldLabelsForAward('mixed', question.question_name);
  }
  return getActiveFieldLabels(state);
}
