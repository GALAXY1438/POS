; TechShift POS — installer customisations (included by electron-builder).
;
; Adds a licence agreement page after the install-directory page. The Next
; button stays disabled until the agreement has been scrolled to the end and
; the accept box ticked. The text is generated at build time from
; www/pos/app/legal/eula.txt into eula-lines.nsh (see scripts/gen-eula-nsh.js).
;
; NOTE: electron-builder prepends this file before MUI2 is included, so all
; code that uses MUI macros lives inside the macro below (expanded later).

!include "nsDialogs.nsh"
!include "LogicLib.nsh"

!define /ifndef SB_TOP    6
!define /ifndef SB_BOTTOM 7

!ifndef BUILD_UNINSTALLER
Var EulaPage
Var EulaEdit
Var EulaHint
Var EulaCheck
Var EulaNextBtn
Var EulaBottomLine
Var EulaReachedEnd

!include "eula-lines.nsh"
!endif

!macro customPageAfterChangeDir

  Function EulaPageShow
    !insertmacro MUI_HEADER_TEXT "Licence agreement" "Read and accept the terms to continue"

    nsDialogs::Create 1018
    Pop $EulaPage
    ${If} $EulaPage == error
      Abort
    ${EndIf}

    StrCpy $EulaReachedEnd 0

    nsDialogs::CreateControl EDIT "${DEFAULT_STYLES}|${WS_TABSTOP}|${ES_MULTILINE}|${ES_AUTOVSCROLL}|${WS_VSCROLL}" ${WS_EX_CLIENTEDGE} 0 0 100% 76%
    Pop $EulaEdit
    ${If} $EulaEdit == error
      Abort
    ${EndIf}

    ; Append line by line (NSIS strings are capped at 1024 chars per call).
    !insertmacro EulaFill
    SendMessage $EulaEdit ${EM_SETREADONLY} 1 0

    ; Work out the top line index when scrolled fully down, then return to top.
    SendMessage $EulaEdit ${WM_VSCROLL} ${SB_BOTTOM} 0
    SendMessage $EulaEdit ${EM_GETFIRSTVISIBLELINE} 0 0 $EulaBottomLine
    SendMessage $EulaEdit ${WM_VSCROLL} ${SB_TOP} 0
    SendMessage $EulaEdit ${EM_SETSEL} 0 0
    SendMessage $EulaEdit ${EM_SCROLLCARET} 0 0

    ${NSD_CreateLabel} 0 78% 100% 12u "Scroll to the end of the agreement to enable the accept box."
    Pop $EulaHint

    ${NSD_CreateCheckbox} 0 84% 100% 12u "I have read and accept the TechShift POS licence agreement"
    Pop $EulaCheck
    EnableWindow $EulaCheck 0

    GetDlgItem $EulaNextBtn $HWNDPARENT 1
    EnableWindow $EulaNextBtn 0

    ${NSD_CreateTimer} EulaTick 250

    nsDialogs::Show
  FunctionEnd

  Function EulaTick
    SendMessage $EulaEdit ${EM_GETFIRSTVISIBLELINE} 0 0 $0

    ${If} $EulaReachedEnd == 0
      ${If} $0 >= $EulaBottomLine
        StrCpy $EulaReachedEnd 1
        EnableWindow $EulaCheck 1
        SendMessage $EulaHint ${WM_SETTEXT} 0 "STR:You have reached the end of the agreement."
      ${EndIf}
    ${EndIf}

    ${NSD_GetState} $EulaCheck $1
    ${If} $EulaReachedEnd == 1
      ${AndIf} $1 == ${BST_CHECKED}
      EnableWindow $EulaNextBtn 1
    ${Else}
      EnableWindow $EulaNextBtn 0
    ${EndIf}
  FunctionEnd

  Function EulaPageLeave
    ${NSD_KillTimer} EulaTick
    ${NSD_GetState} $EulaCheck $0
    ${If} $0 != ${BST_CHECKED}
      Abort
    ${EndIf}
  FunctionEnd

  Page custom EulaPageShow EulaPageLeave

!macroend