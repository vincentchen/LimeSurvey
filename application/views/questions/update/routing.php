<?php
/** @var TbActiveForm $form */
//$form->layout = TbHtml::FORM_LAYOUT_HORIZONTAL;
echo TbHtml::openTag('fieldset', []);
echo $form->textFieldControlGroup($question, 'title');
echo $form->textFieldControlGroup($question, 'relevance');
echo $form->textFieldControlGroup($question, 'a_random_group');
echo $form->checkBoxControlGroup($question, 'bool_mandatory');
echo TbHtml::closeTag('fieldset');
?>