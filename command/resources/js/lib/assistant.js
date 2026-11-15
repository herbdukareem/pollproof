import { reactive } from 'vue';

/** Whether Ask PollProof is open, and a question for it to start with (from a button elsewhere). */
export const assistant = reactive({ open: false, question: '' });

export function askAssistant(question = '') {
    assistant.question = question;
    assistant.open = true;
}

/** Roles that can use it: the command room, not finance. */
export const ASSISTANT_ROLES = ['dg', 'situation_room', 'verifier', 'data_officer', 'legal', 'lga_supervisor', 'ward_supervisor'];
