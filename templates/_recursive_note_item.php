<div class="note-item"
     x-data="noteComponent(note, isRoot ? 0 : nestingLevel + 1, $el)"
     x-init="console.log('Alpine note context (root):', note)"
     :data-note-id="note.id"
     x-sort:item="note.id || note['temp-id']"
     :style="`--nesting-level: ${nestingLevel}`"
     :class="{ 
        'has-children': note.children && note.children.length > 0, 
        'collapsed': note.collapsed, 
        'encrypted-note': note.is_encrypted, 
        'decrypted-note': note.is_encrypted && note.content && !note.content.startsWith('{') 
     }">

    <!-- Row for the bullet, content, etc. -->
    <div class="note-header-row">
        <div class="note-controls">
            <span class="note-collapse-arrow" x-show="note.children && note.children.length > 0" @click="toggleCollapse()" :data-collapsed="note.collapsed ? 'true' : 'false'">
                <i data-feather="chevron-right"></i>
            </span>
            <span class="note-drag-handle" style="display: none;"><i data-feather="menu"></i></span>
            <span class="note-bullet" :data-note-id="note.id" x-sort:handle></span>
        </div>
        <div class="note-content-wrapper">
            <div class="note-content rendered-mode"
                 x-ref="contentDiv"
                 :data-note-id="note.id"
                 :data-raw-content="note.content"
                 x-html="parseContent(note.content)"
                 @click="editNote()"
                 @blur="isEditing = false"
                 @input="handleInput($event)"
                 @paste="handlePaste($event)"
                 @keydown="handleNoteKeyDown($event)">
            </div>
            <div class="note-attachments"></div>
        </div>
    </div>

    <!-- Container for the children of this note -->
    <div class="note-children" 
         :class="{ 'collapsed': note.collapsed }"
         x-sort="handleDrop" 
         x-sort:group="'notesGroup'" 
         :data-parent-id="note.id">
        <template x-for="child in note.children" :key="child.id || child['temp-id']">
            <div x-data="{ isRoot: false }">
                <!-- Inline recursive structure -->
                <div class="note-item"
                     x-data="noteComponent(child, isRoot ? 0 : nestingLevel + 1, $el)"
                     x-init="console.log('Alpine note context (child):', child)"
                     :data-note-id="child.id"
                     x-sort:item="child.id || child['temp-id']"
                     :style="`--nesting-level: ${nestingLevel + 1}`"
                     :class="{ 
                        'has-children': child.children && child.children.length > 0, 
                        'collapsed': child.collapsed, 
                        'encrypted-note': child.is_encrypted, 
                        'decrypted-note': child.is_encrypted && child.content && !child.content.startsWith('{') 
                     }">

                    <div class="note-header-row">
                        <div class="note-controls">
                            <span class="note-collapse-arrow" x-show="child.children && child.children.length > 0" @click="toggleCollapse()" :data-collapsed="child.collapsed ? 'true' : 'false'">
                                <i data-feather="chevron-right"></i>
                            </span>
                            <span class="note-drag-handle" style="display: none;"><i data-feather="menu"></i></span>
                            <span class="note-bullet" :data-note-id="child.id" x-sort:handle></span>
                        </div>
                        <div class="note-content-wrapper">
                            <div class="note-content rendered-mode"
                                 x-ref="contentDiv"
                                 :data-note-id="child.id"
                                 :data-raw-content="child.content"
                                 x-html="parseContent(child.content)"
                                 @click="editNote()"
                                 @blur="isEditing = false"
                                 @input="handleInput($event)"
                                 @paste="handlePaste($event)"
                                 @keydown="handleNoteKeyDown($event)">
                            </div>
                            <div class="note-attachments"></div>
                        </div>
                    </div>

                    <div class="note-children" 
                         :class="{ 'collapsed': child.collapsed }"
                         x-sort="handleDrop" 
                         x-sort:group="'notesGroup'" 
                         :data-parent-id="child.id">
                        <template x-for="grandChild in child.children" :key="grandChild.id || grandChild['temp-id']">
                            <div x-data="{ isRoot: false }">
                                <!-- Recursion: render grandChild using the same structure -->
                                <!-- This block is identical to the parent, so recursion continues -->
                                <div class="note-item"
                                     x-data="noteComponent(grandChild, isRoot ? 0 : nestingLevel + 1, $el)"
                                     x-init="console.log('Alpine note context (grandChild):', grandChild)"
                                     :data-note-id="grandChild.id"
                                     x-sort:item="grandChild.id || grandChild['temp-id']"
                                     :style="`--nesting-level: ${nestingLevel + 2}`"
                                     :class="{ 
                                        'has-children': grandChild.children && grandChild.children.length > 0, 
                                        'collapsed': grandChild.collapsed, 
                                        'encrypted-note': grandChild.is_encrypted, 
                                        'decrypted-note': grandChild.is_encrypted && grandChild.content && !grandChild.content.startsWith('{') 
                                     }">

                                    <div class="note-header-row">
                                        <div class="note-controls">
                                            <span class="note-collapse-arrow" x-show="grandChild.children && grandChild.children.length > 0" @click="toggleCollapse()" :data-collapsed="grandChild.collapsed ? 'true' : 'false'">
                                                <i data-feather="chevron-right"></i>
                                            </span>
                                            <span class="note-drag-handle" style="display: none;"><i data-feather="menu"></i></span>
                                            <span class="note-bullet" :data-note-id="grandChild.id" x-sort:handle></span>
                                        </div>
                                        <div class="note-content-wrapper">
                                            <div class="note-content rendered-mode"
                                                 x-ref="contentDiv"
                                                 :data-note-id="grandChild.id"
                                                 :data-raw-content="grandChild.content"
                                                 x-html="parseContent(grandChild.content)"
                                                 @click="editNote()"
                                                 @blur="isEditing = false"
                                                 @input="handleInput($event)"
                                                 @paste="handlePaste($event)"
                                                 @keydown="handleNoteKeyDown($event)">
                                            </div>
                                            <div class="note-attachments"></div>
                                        </div>
                                    </div>

                                    <!-- Recursively render further children -->
                                    <div class="note-children" 
                                         :class="{ 'collapsed': grandChild.collapsed }"
                                         x-sort="handleDrop" 
                                         x-sort:group="'notesGroup'" 
                                         :data-parent-id="grandChild.id">
                                        <template x-for="greatGrandChild in grandChild.children" :key="greatGrandChild.id || greatGrandChild['temp-id']">
                                            <div x-data="{ isRoot: false }">
                                                <!-- Recursion continues as needed -->
                                                <!-- You can extract this block to a <template> for DRYness if desired -->
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div> 